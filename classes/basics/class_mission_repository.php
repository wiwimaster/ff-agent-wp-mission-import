<?php

class ffami_mission_repository {

    /** Einzelne Mission über CPT-ID laden. */
    public function get(int $post_id) : ?ffami_mission {
        return ffami_mission::from_post($post_id);
    }

    /** Mission anhand ffami_mission_id Meta finden. */
    public function find_by_mission_id(string $mission_id) : ?ffami_mission {
        $q = new WP_Query([
            'post_type' => 'mission',
            'meta_query' => [ [ 'key'=>'ffami_mission_id','value'=>$mission_id,'compare'=>'=' ] ],
            'posts_per_page' => 1,
            'fields' => 'ids'
        ]);
        if ($q->have_posts()) { return $this->get((int)$q->posts[0]); }
        return null;
    }

    /**
     * Sucht Missionen mit optionalen Filtern (Jahr, Typen) und liefert
     * Objektliste + Gesamtzahlen zurück.
     * Parameter: per_page, paged, year, types[], order, orderby.
     */
    public function query(array $args = []) : array {
        $defaults = [
            'per_page' => 20,
            'paged' => 1,
            'year' => null,
            'types' => [],
            'orderby' => 'date',
            'order' => 'DESC'
        ];
        $a = wp_parse_args($args, $defaults);
        $wpArgs = [
            'post_type' => 'mission',
            'posts_per_page' => (int)$a['per_page'],
            'paged' => (int)$a['paged'],
            'orderby' => $a['orderby'],
            'order' => $a['order'],
            'no_found_rows' => false,
        ];
        if ($a['year']) { $wpArgs['date_query'] = [ [ 'year' => (int)$a['year'] ] ]; }
        if ($a['types']) {
            $wpArgs['meta_query'] = [ [ 'key'=>'ffami_mission_type','value'=>array_map('sanitize_text_field',(array)$a['types']),'compare'=>'IN' ] ];
        }
        $q = new WP_Query($wpArgs);
        if (!$q->have_posts()) { return ['missions'=>[], 'total'=>0, 'max_pages'=>0]; }
        $missions = [];
        foreach ($q->posts as $pid) {
            $m = ffami_mission::from_post((int)$pid);
            if ($m) { $missions[] = $m; }
        }
        return [ 'missions'=>$missions, 'total'=>$q->found_posts, 'max_pages'=>$q->max_num_pages ];
    }
}
