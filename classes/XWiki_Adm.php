<?php

use Guzzle\Http\Client as Guzzle_Client;
use Guzzle\Http\Message\Request as Guzzle_Request;
use Guzzle\Common\Event as Guzzle_Event;

/**
 * Main class of the wp-xwiki-adm plugin, contains all the actual coworker synchronization code.
 */
class XWiki_Adm
{

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
     * Fetch coworkers from JSON service page on XWiki (XWiki.CoworkersService).
     *
     * @return \Guzzle\Http\Message\Response
     */
    public static function get_coworkers()
    {
        $client = self::json_client();
        $request = $client->get('xwiki/bin/get/XWiki/CoworkersService?xpage=plain&outputSyntax=plain')
            ->setAuth('JeromeVelociter', 'JeromeVelociter');;
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
            $update_date->setTimezone(new DateTimeZone(ini_get('date.timezone')));
            $update_date->setTimestamp($coworker['_update_date'] / 1000);
            $coworker['update_date'] = $update_date->format('Y-m-d H:i:s');

            $slug = sanitize_title($coworker['first_name'] . ' ' . $coworker['last_name']);

            $post = XWiki_Adm::get_coworker_post_by_slug($slug);

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
            }

            if ($post) {
                $coworker['_post'] = $post;
                $coworker['_post_id'] = $post->ID;
                $coworker['_post_link'] = get_permalink($post->ID);
                $coworker['_post_slug'] = $post->post_name;

                $sync_date = DateTime::createFromFormat('Y-m-d H:i:s', $post->post_modified);

                // WP date is stored as GMT, not in local time, thus we compute the offset and take it off the post
                // modified date so that the comparison is correct
                $dtz = new DateTimeZone(ini_get('date.timezone'));
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

        // WARNING: This gives back only the custom fields that HAVE ALREADY BEEN SET
        // TODO: find a way to get the definition list instead
        $custom_fields = get_post_custom($post->ID);

        foreach ($custom_fields as $key => $value) {
            $actual_key = substr($key, 1);
            if (isset($coworker[$actual_key])) {
                $value = $coworker[$actual_key];

                // Update the meta
                update_post_meta($post->ID, $key, $value);
            }
        }

        wp_set_post_terms($post->ID, $coworker['tags'], 'adm_coworker_tag');

        self::synchronize_profile_picture($coworker);

        // Force pdate last modification date
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
            $request->setAuth('JeromeVelociter', 'JeromeVelociter');
            $request->setResponseBody($file);
            $request->getCurlOptions()->set(CURLOPT_SSL_VERIFYHOST, false);
            $request->getCurlOptions()->set(CURLOPT_SSL_VERIFYPEER, false);
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

    private static function local_date_i18n($format, $timestamp) {
        $timezone_str = get_option('timezone_string') ?: 'UTC';
        $timezone = new \DateTimeZone($timezone_str);

        // The date in the local timezone.
        $date = new \DateTime(null, $timezone);
        $date->setTimestamp($timestamp);
        $date_str = $date->format('Y-m-d H:i:s');

        // Pretend the local date is UTC to get the timestamp
        // to pass to date_i18n().
        $utc_timezone = new \DateTimeZone('UTC');
        $utc_date = new \DateTime($date_str, $utc_timezone);
        $timestamp = $utc_date->getTimestamp();

        return date_i18n($format, $timestamp, true);
    }

} 