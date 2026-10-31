<?php

/**
 * Bootstrap des MP-Feuer-Moduls (Endpunkt, Warteschlange, Zuordnung).
 */
class ffami_mpfeuer_module {

    public function __construct() {
        new ffami_mpfeuer_queue();
        new ffami_mpfeuer_endpoint();
        new ffami_mpfeuer_matcher();
    }
}
