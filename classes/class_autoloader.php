<?php
/**
 * Class autoloader
 * 
 * This class provides an autoloading mechanism for the bmg_plugin namespace.
 */
class ffami_autoloader {
    /**
     * Autoloads the specified class.
     * 
     * @param string $class_name The name of the class to autoload.
     * @throws \Exception If the class file is not found.
     */
    public static function autoload($class_name) {
        if (strpos($class_name, 'ffami_') === 0) {
            // Remove the 'ffami_' prefix from the class name
            $class_name = substr($class_name, strlen('ffami_'));

            // Generate the file name based on the class name
            $class_file = 'class_' . $class_name . '.php';

            // Generate the file path based on the class file name
            $class_path = __DIR__ . '/' . str_replace('_', '/', $class_file);

            if (file_exists($class_path)) {
                // If the class file exists, require it
                require_once $class_path;
            } else {
                // If the class file is not found in the current directory,
                // search for it recursively in subdirectories

                // Create a RecursiveIteratorIterator to iterate through directories
                $directories = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(__DIR__));

                // Flag to track if the class file is found
                $file_found = false;

                // Iterate through each directory
                foreach ($directories as $file) {
                    if ($file->isDir()) {
                        // Get the path of the subdirectory
                        $subdirectory = $file->getPathname();

                        // Generate the class path based on the subdirectory and class file name
                        $class_path = $subdirectory . '/' . $class_file;

                        if (file_exists($class_path)) {
                            // If the class file is found in the subdirectory, require it
                            require_once $class_path;
                            $file_found = true;
                            break;
                        }
                    }
                }

                if (!$file_found) {
                    // If the class file is not found in any of the subdirectories, log an error and throw an exception
                    $error_message = "Class file not found: $class_file";
                    error_log($error_message);
                    throw new \Exception($error_message);
                }
            }
        }
    }
}

// Register the autoloader function with the spl_autoload_register function
spl_autoload_register('ffami_autoloader::autoload');
