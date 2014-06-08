<?php

use Guzzle\Http\Client as Guzzle_Client;
use Guzzle\Http\Message\Request as Guzzle_Request;
use Guzzle\Common\Event as Guzzle_Event;

class XWiki_Adm {

    private static $post_type;

    /**
     * Initiates the plugin's environment.
     */
    public static function init() {

        if(!$initHappened) {
            session_start();

            // Initialize the admin screen
            XWiki_Adm_Admin::init();

            // Initialize the "Sync" tool
            XWiki_Adm_Sync::init();

            // Avoid a second execution of this method
            static $initHappened = true;
        }
    }

    public static function get_post_type()
    {
        if (!isset(self::$post_type)) {
            self::$post_type = get_post_type_object('adm_coworker');
        }
        return self::$post_type;
    }

    public static function get_coworkers() {
        $client = self::client();
        $request = $client->get('xwiki/bin/get/XWiki/CoworkersService?xpage=plain&outputSyntax=plain')
            ->setAuth('JeromeVelociter', 'JeromeVelociter');;
        $request->getCurlOptions()->set(CURLOPT_SSL_VERIFYHOST, false);
        $request->getCurlOptions()->set(CURLOPT_SSL_VERIFYPEER, false);
        return $request->send();
    }

    public static function synchronize_all()
    {
        $response = XWiki_Adm::get_coworkers();

        $json = $response->json();
        $coworkers = $json['coworkers'];

        foreach ($coworkers as &$coworker) {

            $update_date = new DateTime();
            $update_date->setTimestamp($coworker['_update_date'] / 1000);
            $coworker['update_date'] = $update_date->format('Y-m-d H:i:s');

            $slug = sanitize_title($coworker['first_name'] . ' ' . $coworker['last_name']);

            $post = XWiki_Adm::get_coworker_post_by_slug($slug);
            if ($post) {
                $coworker['_post'] = $post;
                $coworker['_post_id'] = $post->ID;
                $coworker['_post_link'] = get_permalink($post->ID);
                $coworker['_post_slug'] = $post->post_name;

                $sync_date = DateTime::createFromFormat('Y-m-d H:i:s', $post->post_modified);

                $is_up_to_date = $sync_date > $update_date;

                $coworker['_is_up_to_date'] = $is_up_to_date;
                $coworker['_post_modified'] = $sync_date->format('Y-m-d H:i:s');

                if (!$is_up_to_date || 1==1) {
                    self::synchronize_coworker($coworker);
                }
            }
        }

        return $coworkers;
    }

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
    }

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


    private static function client() {
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