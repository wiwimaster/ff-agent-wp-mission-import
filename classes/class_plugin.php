<?php


class ffami_plugin {

    public function __construct() {


        //define the variables
        $ffami_vars = new ffami_vars();

        //regsiter the custom post type
        new ffami_mission_post_type();

        //load the backend
        new ffami_backend();
    }
}
