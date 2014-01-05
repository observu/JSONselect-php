<?
include("jsonselect.php");
// Assumes test files as provided by https://github.com/lloyd/JSONSelectTests
$testpath = "../JSONSelectTests/level_";

foreach(array(1,2,3) as $level){

  foreach(glob($testpath.$level."/*.json") as $filename){
      echo "test with $filename\n---\n";
      $name = basename($filename);
      $dir = dirname($filename);

      $group =  preg_replace('/\.json$/', '', $name);

      $testdata = file_get_contents($filename);
      $testdataStruct = json_decode($testdata);

      foreach(glob($testpath.$level."/".$group."*.selector") as $testfilename){
         
          $selector = file_get_contents($testfilename);

          echo "\nWITH $selector\n";

          $expected_output = file_get_contents( preg_replace('/\.selector/','.output', $testfilename) );
          $real_output = ""; 
          try{
            $parser= (new JSONSelect($selector));
            foreach($parser->match($testdataStruct) as $r){
                $real_output .= json_encode($r)."\n";
            }
          }catch(Exception $e){
            $real_output .= "Error: ".$e->getMessage();
          }

          $ws = array(' ',"\n","\r");
          if(str_replace($ws,'', $expected_output)==str_replace($ws,'',$real_output)){
            echo "SUCCESS\n";
          }else{
            echo "FAIL\n";
            print_r($parser->sel);
            echo "-expected:\n\n";
            echo $expected_output;
            echo "\n\n-actual:\n\n";
            echo $real_output."\n";
          }

      }


  }



}




?>


