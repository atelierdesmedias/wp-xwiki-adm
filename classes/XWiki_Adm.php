<?php

use Guzzle\Http\Client as Guzzle_Client;
use Guzzle\Http\Message\Request as Guzzle_Request;
use Guzzle\Common\Event as Guzzle_Event;

/**
 * Main class of the wp-xwiki-adm plugin, contains all the actual coworker synchronization code.
 */
class XWiki_Adm
{
    private static $service_page = 'xwiki/bin/get/XWiki/CoworkersService?xpage=plain&outputSyntax=plain';

    /**
     * Initiates the plugin's environment.
     */
    public static function init()
    {

        if (!$initHappened) {
            session_start();

            // Initialize the admin screen
            XWiki_Adm_Admin::init();

            // Initialize the "Sync" tool
            XWiki_Adm_Sync::init();

            // Avoid a second execution of this method
            static $initHappened = true;
        }
    }

    /**
     * Test if the connection to the XWiki ADM Intranet is correctly configured
     *
     * @return true if it is, false if not
     */
    public static function test_connection()
    {
        $endpoint = get_option('xwiki_adm_endpoint');
        $client = new Guzzle_Client($endpoint);
        $request = $client->get('/');
        $request->setAuth(get_option('xwiki_adm_user'), get_option('xwiki_adm_pass'));
        $request->getCurlOptions()->set(CURLOPT_SSL_VERIFYHOST, false);
        $request->getCurlOptions()->set(CURLOPT_SSL_VERIFYPEER, false);
        try {
            $request->send();
            return true;
        }
        catch (\Guzzle\Http\Exception\ClientErrorResponseException $e) {
            return false;
        }
    }

    /**
     * Fetch coworkers from JSON service page on XWiki (XWiki.CoworkersService).
     *
     * @return \Guzzle\Http\Message\Response
     */
    public static function get_coworkers()
    {
        $client = self::json_client();
        $request = $client->get(self::$service_page);
        $request->setAuth(get_option('xwiki_adm_user'), get_option('xwiki_adm_pass'));
        $request->getCurlOptions()->set(CURLOPT_SSL_VERIFYHOST, false);
        $request->getCurlOptions()->set(CURLOPT_SSL_VERIFYPEER, false);
        return $request->send();
    }

    /**
     * Synchronizes all coworker profiles.
     *
     * @return mixed
     */
    public static function synchronize_all()
    {
        $response = XWiki_Adm::get_coworkers();

        $json = $response->json();
        $coworkers = $json['coworkers'];

        foreach ($coworkers as &$coworker) {

            $update_date = new DateTime();
            $tz = ini_get('date.timezone');
            if (!isset($tz) || $tz === '') {
              $tz = "Europe/Paris";
            }
            $update_date->setTimezone(new DateTimeZone($tz));
            $update_date->setTimestamp($coworker['_update_date'] / 1000);
            $coworker['update_date'] = $update_date->format('Y-m-d H:i:s');

            $slug = sanitize_title($coworker['first_name'] . ' ' . $coworker['last_name']);

            if (trim($slug) === '') {
                // Ignore if there's no first name and last name
                continue;
            }

            $post = self::get_coworker_post_by_slug($slug);

            $public = $coworker['public_enable'];

            if (!isset($post) && $public) {
                // Creating post for new coworker!
                $new_post = array(
                    'post_title' => $coworker['first_name'] . ' ' . $coworker['last_name'],
                    'post_status' => 'publish',
                    'post_name' => $slug,
                    'post_type' => 'adm_coworker'
                );

                wp_insert_post($new_post);

                $post = XWiki_Adm::get_coworker_post_by_slug($slug);
                $coworker['_sync_action'] = 'created';
            } else if (isset($post) && !$public || $coworker['active'] == 0) {
                // Coworker post exists, but does not want to be public or has been inactivated -> delete (bypass trash)
                wp_delete_post($post->ID, true);
                $coworker['_sync_action'] = 'removed';
                unset($post);
            }

            if ($post) {
                // Post exists : check if it needs to be updated

                $coworker['_post'] = $post;
                $coworker['_post_id'] = $post->ID;
                $coworker['_post_link'] = get_permalink($post->ID);
                $coworker['_post_slug'] = $post->post_name;

                $sync_date = DateTime::createFromFormat('Y-m-d H:i:s', $post->post_modified);

                // WP date is stored as GMT, not in local time, thus we compute the offset and take it off the post
                // modified date so that the comparison is correct
                $dtz = new DateTimeZone($tz);
                $dt = new DateTime("now", $dtz);
                $offset = $dtz->getOffset($dt);
                $is_up_to_date = ($sync_date->format('U') - $offset) > $update_date->format('U');

                $coworker['_is_up_to_date'] = $is_up_to_date;
                $coworker['_post_modified'] = $sync_date->format('Y-m-d H:i:s');

                if (!$is_up_to_date || $coworker['_sync_action'] == 'created') {

                    if (!isset($coworker['_sync_action'])) {
                        $coworker['_sync_action'] = 'updated';
                    }

                    self::synchronize_coworker($coworker);
                }
            }
        }

        return $coworkers;
    }

    /**
     * Synchronizes a single coworker profile
     *
     * @param $coworker the coworker to synchronize
     */
    public static function synchronize_coworker($coworker)
    {
        $post = $coworker['_post'];
        
        foreach (array_keys($coworker) as $key) {

            // Verify if key is not blacklisted (starts with a underscore), in which case, ignore this key
            if (strpos($key, '_') === 0) {
                continue;
            }

            $wp_key = '_'.$key;
            $value = $coworker[$key];
            update_post_meta($post->ID, $wp_key, $value);
        }

        wp_set_post_terms($post->ID, $coworker['_tags'], 'adm_coworker_tag');

        self::synchronize_profile_picture($coworker);

        // Force update last modification date
        wp_update_post($post);
    }
    
    /**
     * Downloads and set as featured image the profile picture of a coworker if necessary.
     *
     * @param $coworker the coworker whose picture to download and set as featured image
     */
    public static function synchronize_profile_picture($coworker)
    {
        $post = $coworker['_post'];

        $profile_pic = $coworker['_profile_picture'];
        $version = $coworker['_profile_picture_version'];

        if (isset($profile_pic)) {
            $upload_dir = wp_upload_dir();
            $filename = basename($profile_pic);

            // Construct the file name with its version on XWiki has a prefix, so that it gets updated properly if a
            // new version is uploaded
            $filename = sanitize_file_name($version . '_' . $filename);

            // Check if current featured image has the same name
            $current_featured_image_id = get_post_thumbnail_id($post->ID);
            if (isset($current_featured_image_id)) {
                $current_featured_image = wp_get_attachment_metadata($current_featured_image_id);
                $current_file_name = basename($current_featured_image['file']);

                if ($current_file_name == $filename) {
                    // This is likely the same file : ignore
                    return;
                }
            }

            if (wp_mkdir_p($upload_dir['path'])) {
                $file = $upload_dir['path'] . '/' . $filename;
            } else {
                $file = $upload_dir['basedir'] . '/' . $filename;
            }

            $client = new Guzzle\Http\Client();
            $request = $client->get($profile_pic);
            $request->setAuth(get_option('xwiki_adm_user'), get_option('xwiki_adm_pass'));
            $request->setResponseBody($file);
            $request->getCurlOptions()->set(CURLOPT_SSL_VERIFYHOST, false);
            $request->getCurlOptions()->set(CURLOPT_SSL_VERIFYPEER, false);
            try {
                $request->send();

                $wp_filetype = wp_check_filetype($filename, null);
                $attachment = array(
                    'post_mime_type' => $wp_filetype['type'],
                    'post_title' => sanitize_file_name($filename),
                    'post_content' => '',
                    'post_status' => 'inherit'
                );
                $attach_id = wp_insert_attachment($attachment, $file, $post->ID);
                require_once(ABSPATH . 'wp-admin/includes/image.php');
                $attach_data = wp_generate_attachment_metadata($attach_id, $file);
                wp_update_attachment_metadata($attach_id, $attach_data);

                set_post_thumbnail($post->ID, $attach_id);

            } catch (\Guzzle\Http\Exception\ClientErrorResponseException $e) {
              // Probably a 404, just ignore
            }
        }
    }

    /**
     * Gets the WP custom post of a coworker from its slug
     *
     * @param $slug the slug of the coworker to get the post of
     * @return null or the post object found
     */
    public static function get_coworker_post_by_slug($slug)
    {
        $posts = get_posts(array(
            'name' => $slug,
            'posts_per_page' => 1,
            'post_type' => 'adm_coworker',
            'post_status' => 'publish'
        ));

        if (!$posts) {
            return null;
        }

        return $posts[0];
    }

    /**
     * Gets a JSON client for the XWiki Intranet.
     *
     * @return Guzzle_Client
     * @throws RuntimeException
     */
    private static function json_client()
    {
        static $client = null;

        if ($client == null) {
            $endpoint = get_option('xwiki_adm_endpoint');

            if (!empty($endpoint)) {
                $client = new Guzzle_Client($endpoint);

                // Accept self-signed certificates

                $client->setDefaultOption('headers/Accept', 'application/json');
            } else {
                throw new RuntimeException('The XWiki server is not set.');
            }
        }

        return $client;
    }

} 
