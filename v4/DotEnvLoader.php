<?php
    $env_file_path = realpath(__DIR__."/.env");
    // echo "DotEnvLoader.php path=".$env_file_path."\n";
    //Check .envenvironment file exists
    if(!is_file($env_file_path)){
        echo "NO FILE";
        throw new ErrorException("Environment File is Missing.");
    }
    //Check .envenvironment file is readable
    if(!is_readable($env_file_path)){
        throw new ErrorException("Permission Denied for reading the ".($env_file_path).".");
    }
    //Check .envenvironment file is writable
    // if(!is_writable($env_file_path)){
    //     throw new ErrorException("Permission Denied for writing on the ".($env_file_path).".");
    $var_arrs = array();
    // Open the .en file using the reading mode
    $fopen = fopen($env_file_path, 'r');
    if($fopen){
        //Loop the lines of the file
        while (($line = fgets($fopen)) !== false){
            // Check if line is a comment
            $line_is_comment = (substr(trim($line),0 , 1) == '#') ? true: false;
            // If line is a comment or empty, then skip
            if($line_is_comment || empty(trim($line)))
                continue;
 
            // Split the line variable and succeeding comment on line if exists
            $line_no_comment = explode("#", $line, 2)[0];
            // Split the variable name and value
            $env_ex = preg_split('/(\s?)\=(\s?)/', $line_no_comment);
            $env_name = trim($env_ex[0]);
            $env_value = isset($env_ex[1]) ? trim($env_ex[1]) : "";
            $var_arrs[$env_name] = $env_value;
        }
        // Close the file
        fclose($fopen);
    }
    foreach($var_arrs as $name => $value){
        //Using putenv()
        // putenv("{$name}={$value}");
        // echo "DotEnvLoader.php name=$name val=$value \n";
        //Or, using $_ENV
        $_ENV[$name] = $value;
 
        // Or you can use both
    }
?>