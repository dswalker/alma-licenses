<?php

// ini_set('display_errors', '1');

include("vendor/autoload.php");

$config = include('config.php');

// process the uploaded excel file

if (isset($_FILES['excel'])) {
    
    $errors = array();
    
    $file_name = $_FILES['excel']['name'];
    $file_size = $_FILES['excel']['size'];
    $file_tmp = $_FILES['excel']['tmp_name'];

    if ($file_size > 2097152) {
        $errors[] = 'File size is too large';
    }
    
    // move the file from tmp to our local dir

    if (empty($errors) == true) {
        $file_name = preg_replace('/[^A-Za-z0-9\-\.]/', '', $file_name);
        move_uploaded_file($file_tmp, "files/".$file_name);
        $file_path =  getcwd() . '/files/' . $file_name . '';
    } else {
        echo 'Error opening file';
    }
    
    // process em!
    read_csv($file_path, $config['api_key'], $config['base_url']);
}

// parses the license terms excel spreadsheet

function read_csv($licenses, $key, $baseurl)
{
    //  read your excel workbook
    try {
        $excel_data = array();
        $objPHPExcel = PHPExcel_IOFactory::load($licenses);
        $worksheet = $objPHPExcel->getSheet(0);
        $highestRow = $worksheet->getHighestRow(); // e.g. 10

        for ($row = 1; $row <= $highestRow; ++ $row) {
            $cell_1 = $worksheet->getCellByColumnAndRow(1, $row);
            $response = $cell_1->getValue();
            $cell_2 = $worksheet->getCellByColumnAndRow(2, $row);
            $code = $cell_2->getValue();

            if ($response != 'Enter License term values here:' && $code != 'LicenseCode'){
                $excel_data[$code] = $response;
            }

            if ($code == 'LicenseCode'){
                $license_code = $response;
            }
        }

        $license_xml = get_license($license_code, $baseurl, $key);

        if (isset($license_xml)) {
            $updated_license = update_terms($license_xml, $excel_data);
            put_license( $baseurl, $key, $license_code, $updated_license);
        } else {
            echo 'Error';
        }
    } catch(Exception $e) {
        die('Error loading file "' . pathinfo($licenses, PATHINFO_BASENAME) .
            '": ' . $e->getMessage());
    }
}

// returns the license xml from the alma api

function get_license($code, $baseurl, $key)
{
    $url = $baseurl . '/acq/licenses/' . $code . '?apikey=' . $key;
    $curl = curl_init($url);
    curl_setopt($curl, CURLOPT_HEADER, false);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($curl, CURLOPT_HTTPHEADER, array("Content-type: application/xml"));
    $response = curl_exec($curl);
    curl_close($curl);

    try {
        $xml = new SimpleXMLElement($response);
        return $xml;
    } catch(Exception $exception) {
        echo $exception; exit;
    }
}

// makes a put request for our updated license

function put_license($baseurl, $key, $code, $data)
{
    $data = makexml($data);
    $url = $baseurl . '/acq/licenses/' . $code . '?apikey=' . $key;
    $curl = curl_init($url);
    curl_setopt($curl, CURLOPT_HTTPHEADER, array("Content-type: application/xml"));
    curl_setopt($curl, CURLOPT_CUSTOMREQUEST, "PUT");
    curl_setopt($curl, CURLOPT_POSTFIELDS, $data);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($curl);
    curl_close($curl);

    try {
        $xml = new SimpleXMLElement($response);
        check_errors($xml);
    } catch(Exception $exception) {
        echo $exception; exit;
    }
}

// updates the license xml data with the updated/new terms

function update_terms($license, $new_terms)
{
    $terms = $license->xpath('//license/terms/term');
    $caps = array('No', 'Yes', 'Not Applicable', 'Uninterpreted', 'Permitted' ,
        'Prohibited', 'Silent', 'Calendar day', 'Month', 'Business day', 'Week',
        'Automatic','Explicit');

    foreach ( $new_terms as $key => $value) {

        if (is_string($value) && in_array($value,$caps)) {
            $value = strtoupper($value);
        }

        $match_found = false;

        if (isset($value) && $value != 'Please choose a value') {
            // check and see if the value already exists in the xml
            for($i = 0; $i<count($terms); $i++) {
                if ($terms[$i]->code == $key) {
                    $match_found = true;
                    $terms[$i]->value = $value;
                }
            }

            if (!$match_found) {
                add_term($license,$key,$value);
            }
        }
    }
    return $license;
}

// adds the xml term node with the updated term info

function add_term($license, $code, $value)
{
    $terms = $license->terms;
    $new_term = $terms->addChild("term");
    $new_code = $new_term->addChild("code",strtoupper($code));
    $new_code->addAttribute("desc",$code);
    $new_value = $new_term->addChild("value",$value);
    $new_value->addAttribute("desc",$value);
    return $terms;
}

// display errors

function check_errors($xml)
{
    if($xml->errorsExist == "true") {
        echo "Error";
    } else {
        echo "Success";
    }
}

// converts our simplexml back to xml string

function makexml($xml)
{
    $doc = new DOMDocument();
    $doc->formatOutput = TRUE;
    $doc->loadXML($xml->asXML());
    $return_xml = $doc->saveXML();
    return $return_xml;
}
