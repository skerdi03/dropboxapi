<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Http;
use Illuminate\Http\Client\Pool;

class APIController extends Controller
{
    function __construct(){
        $this->token = "sgghub7rVUIAAAAAAAAAAVDLRbuUlhXF_eTcTMP5f_RC7lOEFVwsRI0jkcbrnuSq";
        $this->api = "https://api.dropboxapi.com/2/files/list_folder";
        $this->continueApi = $this->api.'/continue';
    }

    function oneResponse($response, &$array_of_folder, &$listOfPaths, &$totalProcess){

        if( !$response->ok() ) return; //if status code is not 200 return or we can make request again or save logs of this case

        $body = $response->json();
        $entries = $body["entries"];
        if( count($entries) == 0) return;
        
        foreach($entries as $entry){
            $foldername = $entry["path_lower"];
            array_push($listOfPaths, $foldername);
            $entry[".tag"] == "folder" && array_push($array_of_folder, $foldername);
        }
        if($body["has_more"] == true){ //If the request has more entries than limit //pagination endpoint

            $continueResponse = Http::retry(10, 1000, function ($exception) {
                //after trying for 10 times. There is something worng here.
                return $exception instanceof ConnectionException;
            })->timeout(10000)->acceptJson()->withToken($this->token)->post($this->continueApi, ["cursor" => $body["cursor"]]);
            $totalProcess += 1;
            $this->oneResponse($continueResponse, $array_of_folder, $listOfPaths, $totalProcess);
        }
    }

    function recursiveParalelProcess(&$listOfPaths, $pathsToLoop, &$parallelProcess, &$totalProcess){
        $parallelProcess +=1;
        $responses = Http::pool(function (Pool $pool) use($pathsToLoop, &$totalProcess){ // make multiple HTTP requests concurrently with Pool
            foreach($pathsToLoop as $path){
                // automatically retry 10 time the request every 1 second if a client or server error occurs.
                //Don't wait more than 10 second for the response
                $pool->retry(10, 1000, function ($exception) {
                    //after trying for 10 times. There is something worng here.
                    return $exception instanceof ConnectionException;
                })->timeout(10000)->acceptJson()->withToken($this->token)->post($this->api, ["path" => $path]);
                $totalProcess += 1;
            }   
        });
       
        $array_of_folder = array();
        foreach($responses as $response){
            if(get_class($response) == "Illuminate\Http\Client\Response"){ //else we can resend faild request or save logs of this case
                $this->oneResponse($response, $array_of_folder, $listOfPaths, $totalProcess);
            }
        }
    
        count($array_of_folder) > 0 &&  $this->recursiveParalelProcess($listOfPaths, $array_of_folder, $parallelProcess, $totalProcess);
        
    }

    function readPathFromAPI(){
        $startTime = date("H:i:s");
        $parallelProcess = 0;
        $totalProcess = 0;
        $listOfPaths = array();
        $pathsToLoop = [""];
    
        $this->recursiveParalelProcess($listOfPaths, $pathsToLoop, $parallelProcess, $totalProcess);

        $endTime = date("H:i:s");
        return view("listpaths")->with(compact("startTime", "endTime", "totalProcess", "parallelProcess", "listOfPaths"));

    }

}