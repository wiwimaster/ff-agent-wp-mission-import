<?php
/**
 * Autoloader für alle Klassen mit Präfix ffami_.
 *
 * Sucht die entsprechende Datei (Namensschema class_<teil>.php) zunächst
 * direkt, anschließend rekursiv. Gefundene Pfade werden gecached, nicht
 * gefundene (false) ebenfalls, um wiederholte teure Directory-Scans zu vermeiden.
 */
class ffami_autoloader {
    /**
     * Cache bereits aufgelöster Klassen => Pfad
     * @var array<string,string|false>
     */
    private static array $resolved = [];
    /**
     * Lädt eine Klasse mit Präfix ffami_. Andere Namen werden ignoriert.
     *
     * @param string $class_name Vollständiger Klassenname.
     */
    public static function autoload($class_name) {
        if (strpos($class_name, 'ffami_') === 0) {
            if (isset(self::$resolved[$class_name])) {
                $cached = self::$resolved[$class_name];
                if ($cached && file_exists($cached)) { require_once $cached; }
                return;
            }
            // Remove the 'ffami_' prefix from the class name
            $class_name = substr($class_name, strlen('ffami_'));

            // Generate the file name based on the class name
            $class_file = 'class_' . $class_name . '.php';

            // Generate the file path based on the class file name
            $class_path = __DIR__ . '/' . str_replace('_', '/', $class_file);

            if (file_exists($class_path)) {
                require_once $class_path;
                self::$resolved['ffami_' . $class_name] = $class_path;
            } else {
                // If the class file is not found in the current directory,
                // search for it recursively in subdirectories

                // Create a RecursiveIteratorIterator to iterate through directories
                $directories = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(__DIR__));

                // Flag to track if the class file is found
                $file_found = false;

                // Iterate through each directory
                foreach ($directories as $file) {
                    if (!$file->isDir()) { continue; }
                    $subdirectory = $file->getPathname();
                    $candidate = $subdirectory . '/' . $class_file;
                    if (file_exists($candidate)) {
                        require_once $candidate;
                        $file_found = true;
                        self::$resolved['ffami_' . $class_name] = $candidate;
                        break;
                    }
                }

                if (!$file_found) {
                    // If the class file is not found in any of the subdirectories, log an error and throw an exception
                    $error_message = "Class file not found: $class_file";
                    self::$resolved['ffami_' . $class_name] = false; // negative cache
                    if (defined('WP_DEBUG') && WP_DEBUG) {
                        error_log($error_message);
                    }
                }
            }
        }
    }
}

// Register the autoloader function with the spl_autoload_register function
spl_autoload_register('ffami_autoloader::autoload');
