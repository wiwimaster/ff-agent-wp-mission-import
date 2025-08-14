<?php

class ffami_blocks {
    public function __construct() {
        add_action('init', [$this, 'register_blocks']);
    }

    public function register_blocks() : void {
        $root_dir  = dirname( plugin_dir_path( __FILE__ ) ); // plugin root
        $block_dir = $root_dir . '/blocks/mission-table-latest';

        if ( file_exists( $block_dir . '/block.json' ) ) {
            $this->register_editor_assets( $root_dir );
            register_block_type_from_metadata( $block_dir, [
                'render_callback' => [ $this, 'render_latest_missions_table' ],
            ] );
        } else {
            register_block_type( 'ffami/mission-table-latest', [
                'api_version'      => 2,
                'title'            => 'FF Einsätze – Letzte Missionen',
                'description'      => 'Zeigt die letzten Einsätze als Tabelle.',
                'category'         => 'widgets',
                'attributes'       => [ 'limit' => [ 'type' => 'number', 'default' => 20 ] ],
                'supports'         => [ 'html' => false ],
                'render_callback'  => [ $this, 'render_latest_missions_table' ],
            ] );
        }
    }

    private function register_editor_assets( string $root_dir ) : void {
        $handle = 'ffami-mission-table-editor';
        $src    = plugins_url( '../blocks/mission-table-latest/index.js', __FILE__ );
        $path   = $root_dir . '/blocks/mission-table-latest/index.js';
        $ver    = file_exists( $path ) ? filemtime( $path ) : false;
        wp_register_script(
            $handle,
            $src,
            [ 'wp-blocks', 'wp-element', 'wp-components', 'wp-editor' ],
            $ver,
            true
        );
    }

    public function render_latest_missions_table($attributes = [], $content = '') : string {
        // Unified per-page setting (fall back to legacy keys if present)
        $per_page = isset($attributes['perPage']) ? (int)$attributes['perPage'] : ( isset($attributes['pageSize']) ? (int)$attributes['pageSize'] : ( isset($attributes['limit']) ? (int)$attributes['limit'] : 20 ) );
        if ($per_page <= 0 || $per_page > 500) { $per_page = 20; }

        $pagination_enabled = !empty($attributes['pagination']);
        $pagination_mode = isset($attributes['paginationMode']) && in_array($attributes['paginationMode'], ['simple','year'], true) ? $attributes['paginationMode'] : 'simple';
        $pagination_position = isset($attributes['paginationPosition']) && in_array($attributes['paginationPosition'], ['below','above','both'], true) ? $attributes['paginationPosition'] : 'below';
        $pagination_id = isset($attributes['paginationId']) ? preg_replace('/[^a-zA-Z0-9_-]/','',$attributes['paginationId']) : '1';
        if ($pagination_id === '') { $pagination_id = '1'; }
        $page_var = 'ffami_page_' . $pagination_id;
        $year_var = 'ffami_year_' . $pagination_id;
        $current_page = $pagination_enabled && isset($_GET[$page_var]) ? max(1, (int)$_GET[$page_var]) : 1;
        $filter_types = isset($attributes['filterTypes']) && is_array($attributes['filterTypes']) ? array_filter(array_map('sanitize_text_field',$attributes['filterTypes'])) : [];

        // Year handling
        $selected_year = null;
        $years = [];
        if ($pagination_enabled && $pagination_mode === 'year') {
            if (isset($_GET[$year_var])) {
                $selected_year = (int)$_GET[$year_var];
            }
            $years_cache_key = 'ffami_block_years';
            $years = get_transient($years_cache_key);
            if (!$years) {
                global $wpdb;
                $results = $wpdb->get_col( "SELECT DISTINCT YEAR(post_date) FROM {$wpdb->posts} WHERE post_type='mission' AND post_status='publish' ORDER BY post_date DESC" );
                $years = array_map('intval', $results ?: []);
                set_transient($years_cache_key, $years, HOUR_IN_SECONDS);
            }
            if (!$selected_year) {
                $currentYear = (int)date('Y');
                if (in_array($currentYear, $years, true)) {
                    $selected_year = $currentYear;
                } elseif (!empty($years)) {
                    $selected_year = $years[0];
                }
            }
            if ($selected_year && ($selected_year < 2000 || $selected_year > (int)date('Y') + 1)) { $selected_year = null; }
        }

        $page_size = $per_page; // even without pagination we show first page with per_page limit

        // Columns
        $default_cols = ['date','title','type','duration','persons','location'];
        $columns = isset($attributes['columns']) && is_array($attributes['columns']) && $attributes['columns'] ? array_values(array_intersect($attributes['columns'], $default_cols)) : $default_cols;
        if (empty($columns)) { $columns = $default_cols; }

        // Query args
        $query_args = [
            'post_type' => 'mission',
            'posts_per_page' => $page_size,
            'orderby' => 'date',
            'order' => 'DESC',
            'paged' => $current_page,
            'no_found_rows' => !$pagination_enabled,
        ];
        if ($pagination_enabled && $pagination_mode === 'year' && $selected_year) {
            $query_args['date_query'] = [ [ 'year' => $selected_year ] ];
        }
        if (!empty($filter_types)) {
            $query_args['meta_query'] = [ [ 'key' => 'ffami_mission_type', 'value' => $filter_types, 'compare' => 'IN' ] ];
        }
    $repo = new ffami_mission_repository();
    $query = new WP_Query($query_args);
    if (!$query->have_posts()) {
            return '<div class="ffami-mission-table ffami-empty">Keine Einsätze vorhanden.</div>';
        }

        ob_start();
        echo '<div class="ffami-mission-table-wrapper">';
        if ($pagination_enabled && ($pagination_position === 'above' || $pagination_position === 'both')) {
            $this->render_pagination_nav($pagination_mode, $pagination_id, $current_page, $page_var, $year_var, $years, $selected_year, $query);
        }

        echo '<table class="ffami-mission-table" style="width:100%; border-collapse:collapse;">';
        // Header
        $labels = [
            'date'     => __('Datum', 'ffami'),
            'title'    => __('Titel', 'ffami'),
            'type'     => __('Typ', 'ffami'),
            'duration' => __('Dauer', 'ffami'),
            'persons'  => __('Personen', 'ffami'),
            'location' => __('Ort', 'ffami')
        ];
        echo '<thead><tr>';
        foreach ($columns as $c) { if (isset($labels[$c])) echo '<th style="text-align:left;">'.esc_html($labels[$c]).'</th>'; }
        echo '</tr></thead><tbody>';

        while ($query->have_posts()) { $query->the_post();
            $pid = get_the_ID();
            $mission = $repo->get($pid);
            if (!$mission) { continue; }
            $dtFmt = $mission->datetime ? date_i18n('d.m.Y H:i', strtotime($mission->datetime)) : '';
            $durationStr = '';
            if ($mission->duration_minutes > 0) {
                $h = intdiv($mission->duration_minutes, 60); $m = $mission->duration_minutes % 60; $durationStr = sprintf('%d:%02d h', $h, $m);
            } elseif ($mission->duration instanceof DateInterval) {
                $h = $mission->duration->h + ($mission->duration->d * 24); $m = $mission->duration->i; $durationStr = sprintf('%d:%02d h', $h, $m);
            }
            $hasImages = $mission->has_images ?? has_post_thumbnail($pid);
            $contentRaw = trim(strip_tags($mission->content));
            $linkable = $hasImages || $contentRaw !== '';
            $titleDisplay = $mission->raw_title !== '' ? $mission->raw_title : $mission->title;
            $titleHtml = $linkable ? '<a href="'.esc_url(get_permalink($pid)).'">'.esc_html($titleDisplay).'</a>' : esc_html($titleDisplay);
            echo '<tr>';
            foreach ($columns as $col) {
                switch ($col) {
                    case 'date': echo '<td>'.esc_html($dtFmt).'</td>'; break;
                    case 'title': echo '<td>'.$titleHtml.'</td>'; break;
                    case 'type': echo '<td>'.esc_html($mission->mission_type).'</td>'; break;
                    case 'duration': echo '<td>'.esc_html($durationStr).'</td>'; break;
                    case 'persons': echo '<td>'.esc_html($mission->person_count).'</td>'; break;
                    case 'location': echo '<td>'.esc_html($mission->location).'</td>'; break;
                }
            }
            echo '</tr>';
        }
        wp_reset_postdata();
        echo '</tbody></table>';

        if ($pagination_enabled && ($pagination_position === 'below' || $pagination_position === 'both')) {
            $this->render_pagination_nav($pagination_mode, $pagination_id, $current_page, $page_var, $year_var, $years, $selected_year, $query);
        }
        echo '</div>';
        return ob_get_clean();
    }

    private function render_pagination_nav($mode, $pagination_id, $current_page, $page_var, $year_var, $years, $selected_year, $query) : void {
        $total_pages = (int)$query->max_num_pages;
        if ($total_pages < 2) { return; }
        if ($mode === 'simple') {
            $base_url = remove_query_arg([$page_var, $year_var]);
            $sep = strpos($base_url,'?') === false ? '?' : '&';
            echo '<nav class="ffami-pagination ffami-pagination-simple" aria-label="Einsatz Seiten"><ul class="ffami-pagination-list" style="list-style:none;display:flex;gap:8px;padding:0;margin:8px 0;flex-wrap:wrap;justify-content:center;">';
            // Prev
            if ($current_page > 1) {
                $prev_url = esc_url( $current_page -1 === 1 ? $base_url : $base_url . $sep . $page_var . '=' . ($current_page -1) );
                echo '<li><a href="'.$prev_url.'">&laquo; Zurück</a></li>';
            }
            $window = 1;
            $pages_to_show = [1];
            for ($p=$current_page-$window; $p<=$current_page+$window; $p++) { if ($p>1 && $p<$total_pages) $pages_to_show[]=$p; }
            if ($total_pages>1) { $pages_to_show[]=$total_pages; }
            $pages_to_show = array_values(array_unique($pages_to_show)); sort($pages_to_show);
            $last = 0;
            foreach ($pages_to_show as $p) {
                if ($last && $p > $last + 1) echo '<li><span class="ffami-gap">…</span></li>';
                if ($p === $current_page) {
                    echo '<li><span class="ffami-current-page" aria-current="page" style="font-weight:bold;">'.$p.'</span></li>';
                } else {
                    $url = esc_url( $p === 1 ? $base_url : $base_url . $sep . $page_var . '=' . $p );
                    echo '<li><a href="'.$url.'">'.$p.'</a></li>';
                }
                $last = $p;
            }
            if ($current_page < $total_pages) {
                $next_url = esc_url( $current_page +1 === 1 ? $base_url : $base_url . $sep . $page_var . '=' . ($current_page +1) );
                echo '<li><a href="'.$next_url.'">Weiter &raquo;</a></li>';
            }
            echo '</ul></nav>';
        } elseif ($mode === 'year') {
            if (empty($years) || !$selected_year) { return; }
            echo '<nav class="ffami-pagination ffami-pagination-years" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;">';
            // Years list
            $base_year_url = remove_query_arg([$year_var, $page_var]);
            $sep_base = strpos($base_year_url,'?') === false ? '?' : '&';
            echo '<div class="ffami-years"><ul style="list-style:none;display:flex;gap:6px;padding:0;margin:0;">';
            foreach ($years as $y) {
                if ($y === $selected_year) {
                    echo '<li><span class="ffami-current-year" aria-current="true" style="font-weight:bold;">'.$y.'</span></li>';
                } else {
                    $url_y = esc_url( $base_year_url . $sep_base . $year_var . '=' . $y );
                    echo '<li><a href="'.$url_y.'">'.$y.'</a></li>';
                }
            }
            echo '</ul></div>';
            // Pages within year (condensed)
            $base_page_url = add_query_arg($year_var, $selected_year, remove_query_arg($page_var));
            $sep_p = strpos($base_page_url,'?') === false ? '?' : '&';
            echo '<div class="ffami-pages"><ul style="list-style:none;display:flex;gap:8px;padding:0;margin:0;">';
            if ($current_page > 1) {
                $prev_url = esc_url( $current_page -1 === 1 ? $base_page_url : $base_page_url . $sep_p . $page_var . '=' . ($current_page -1) );
                echo '<li><a href="'.$prev_url.'">&laquo; Zurück</a></li>';
            }
            $window = 1; $pages_to_show=[1];
            for ($p=$current_page-$window; $p<=$current_page+$window; $p++) { if ($p>1 && $p<$total_pages) $pages_to_show[]=$p; }
            if ($total_pages>1) $pages_to_show[]=$total_pages;
            $pages_to_show = array_values(array_unique($pages_to_show)); sort($pages_to_show); $last=0;
            foreach ($pages_to_show as $p) {
                if ($last && $p>$last+1) echo '<li><span class="ffami-gap">…</span></li>';
                if ($p === $current_page) {
                    echo '<li><span class="ffami-current-page" aria-current="page" style="font-weight:bold;">'.$p.'</span></li>';
                } else {
                    $url_p = esc_url( $p === 1 ? $base_page_url : $base_page_url . $sep_p . $page_var . '=' . $p );
                    echo '<li><a href="'.$url_p.'">'.$p.'</a></li>';
                }
                $last = $p;
            }
            if ($current_page < $total_pages) {
                $next_url = esc_url( $current_page +1 === 1 ? $base_page_url : $base_page_url . $sep_p . $page_var . '=' . ($current_page +1) );
                echo '<li><a href="'.$next_url.'">Weiter &raquo;</a></li>';
            }
            echo '</ul></div>';
            echo '</nav>';
        }
    }
}
