<?php
include 'dl.php';
if($_GET['nm']){
    $new_file_name= base64_decode($_GET['nm']);
    $finalfilename= $id.'-'.$new_file_name;
    $fileurl= base64_decode($_GET['gd']);
    start($fileurl, $finalfilename, 10);
    sleep(6);
    header("Location:  /$finalfilename");
    }
?>
