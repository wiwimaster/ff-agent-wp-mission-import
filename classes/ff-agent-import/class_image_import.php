<?php


class ffami_image_import {

    private ffami_mission $mission;

    private $current_upload_time;

    public function __construct(ffami_mission $mission) {
        $this->mission = $mission;

        $this->process_mission_images();
    }



     /**
     * Process and attach images to the post
     *
     * @return void
     */
    private function process_mission_images() {

        require_once(ABSPATH . 'wp-admin/includes/image.php');
        require_once(ABSPATH . 'wp-admin/includes/file.php');
        require_once(ABSPATH . 'wp-admin/includes/media.php');

        //collect the images
        $image_urls = $this->mission->image_urls;

        // Process the first image as the thumbnail
        $thumbnail_urls = array_shift($image_urls);
        $thumbnail_id = $this->import_image_from_url($thumbnail_urls, $this->mission->post_id);


        if (!is_wp_error($thumbnail_id)) {
            set_post_thumbnail($this->mission->post_id, $thumbnail_id);
        } else {
            error_log('Error attaching thumbnail: ' . $thumbnail_id->get_error_message());
        }

        // Process remaining images as gallery attachments
        if (!empty($image_urls)) {
            $gallery_ids = [];
            foreach ($image_urls as $image_url) {
                $attachment_id = $this->import_image_from_url($image_url, $this->mission->post_id);
                if (!is_wp_error($attachment_id)) {
                    $gallery_ids[] = $attachment_id;
                }
            }

            if (!empty($gallery_ids)) {
                // Append a gallery shortcode at the end of the post content
                $gallery_shortcode = '[gallery ids="' . implode(',', $gallery_ids) . '"]';
                wp_update_post([
                    'ID'           => $this->mission->post_id,
                    'post_content' => $this->mission->content . "\n\n" . $gallery_shortcode,
                ]);
            }
        }
    }



    /**
     * Importiert ein Bild von einer URL in die Mediathek und hängt es an den Post an.
     * Wenn das Bild schon einmal von dieser URL importiert wurde, wird nur die ID zurückgegeben.
     *
     * @param string $thumbnail_url Externe Bild-URL
     * @param int    $post_id       ID des Posts, an den das Bild gehängt werden soll
     * @return int|WP_Error         Attachment-ID oder WP_Error
     */
    private function import_image_from_url($thumbnail_urls, $post_id) {
        $thumbnail_url = $thumbnail_urls['url'] ?? '';

        if (empty($thumbnail_url) || empty($post_id)) {
            return new WP_Error('missing_parameter', 'URL oder Post-ID fehlt.');
        }

        // Wordpress braucht ein .jpg am Ende der URL, um das Bild zu erkennen
        $thumbnail_url = $thumbnail_url . "?a=.jpg";

        // 1) Gibt es schon ein Attachment mit genau dieser externen URL?
        $existing = get_posts(array(
            'post_type'      => 'attachment',
            'post_status'    => 'inherit',
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'meta_query'     => array(
                array(
                    'key'     => '_external_image_url',
                    'value'   => $thumbnail_url,
                    'compare' => '=',
                ),
            ),
        ));

        // Wenn ja, direkt die ID zurückliefern
        if (! empty($existing)) {
            return (int) $existing[0];

        } else {
            // 2) Datum verarbeiten
            $timestamp = $this->mission->datetime ? strtotime($this->mission->datetime) : current_time('timestamp');
            $use_folders = (bool) get_option('uploads_use_yearmonth_folders', 1);

            // 3) Falls Setting aktiv ist und Datum übergeben wurde, filter hinzufügen
            if ($use_folders && $date) {
                $this->current_upload_time = $timestamp;
                add_filter('upload_dir', array($this, 'filter_upload_dir_by_date'));
            }

            // 4) Bild sideloaden
            if (! function_exists('media_sideload_image')) {
                require_once ABSPATH . 'wp-admin/includes/image.php';
                require_once ABSPATH . 'wp-admin/includes/file.php';
                require_once ABSPATH . 'wp-admin/includes/media.php';
            }
            $attach_id = media_sideload_image($thumbnail_url, $post_id, null, 'id');

            // Filter wieder entfernen
            if (isset($this->current_upload_time)) {
                remove_filter('upload_dir', array($this, 'filter_upload_dir_by_date'));
                unset($this->current_upload_time);
            }

            if (is_wp_error($attach_id)) {
                return $attach_id;
            }

            // 5) Externe URL als Meta sichern
            update_post_meta($attach_id, '_external_image_url', esc_url_raw($thumbnail_url));

            // 6) Post-Datum des Attachments setzen
            if ($date) {
                $post_date     = date('Y-m-d H:i:s', $timestamp);
                $post_date_gmt = get_gmt_from_date($post_date);
                wp_update_post(array(
                    'ID'            => $attach_id,
                    'post_date'     => $post_date,
                    'post_date_gmt' => $post_date_gmt,
                ));
            }

            return $attach_id;
        }
    }


    /**
     * Callback für upload_dir, um Jahr/Monat anhand $this->current_upload_time zu erzwingen.
     *
     * @param array $dirs Standard-Pfade/URLs für Uploads
     * @return array Modifizierte Pfade/URLs
     */
    public function filter_upload_dir_by_date($dirs) {
        $t = $this->current_upload_time;
        $sub  = '/' . date('Y/m', $t);
        $dirs['subdir']  = $sub;
        $dirs['path']    = $dirs['basedir'] . $sub;
        $dirs['url']     = $dirs['baseurl'] . $sub;
        return $dirs;
    }
}
