<?php
# steady-state.php
# Called on startup by Zincati
# https://github.com/coreos/zincati/blob/master/docs/images/zincati-fleetlock.png

$debug = false;

# Set a default reponse code so any output will deny a reboot
http_response_code(500);

# Create stream context for API calls
## $verb = GET or PATCH
## $data = Body of request
function getStreamContext($verb = 'GET', $data = null)
{
    # Set options for Kubernetes API call
    $opts = array(
        'ssl' => array(
            'cafile' => '/run/secrets/kubernetes.io/serviceaccount/ca.crt'
        ),
        'http' => array(
            'method' => $verb,
            'header' => implode("\r\n", array(
                "Authorization: Bearer " . file_get_contents('/run/secrets/kubernetes.io/serviceaccount/token')
            )) . "\r\n",
        )
    );
    if($verb == "PATCH")
    {
        $opts['http']['header'] .= "Content-Type: application/merge-patch+json\r\n";
    }
    if($data)
    {
        $opts['http']['content'] = json_encode($data);
    }
    return stream_context_create($opts);
}

# Convert a string of a hex to the ascii characters
function hexToStr($hex){
    $string='';
    for ($i=0; $i < strlen($hex)-1; $i+=2){
        $string .= chr(hexdec($hex[$i].$hex[$i+1]));
    }
    return $string;
}


# Read body from request
# {
#    "client_params": {
#        "id": "0123456789abcdef0123456789abcdef",
#        "group": "default"
#    } 
# }
$request = json_decode(file_get_contents('php://input'), true);

# Define Kubernete API location (internal to kubernetes cluster)
$base = 'https://kubernetes.default.svc/api/v1';

# Set options for Kubernetes API call
$context = getStreamContext();

# Create url string 
$url = $base . '/nodes';

# Get node information
$handle = fopen($url, 'r', false, $context);
if ($handle === false) {
    $e = error_get_last();
    error_log($e['message'], $e['type']);
    flush();
    die();
}
$response = stream_get_contents($handle);
fclose($handle);

$response = json_decode($response, true);

# App ID used to generate app specific machine ID
$appID = "de35106b6ec24688b63afddaa156679b";
# Only compare the first 12 and last 14 characters. The 13th and 17th characters sometimes come out wrong for the same input
$id128hash = $request["client_params"]["id"];
$id128hashp1 = substr($id128hash, 0, 12);
$id128hashp2 = substr($id128hash, 18, 14);

# Find the node that made the request
foreach($response["items"] as $machine)
{
    $phphash = hash_hmac("sha256", hexToStr($appID), hexToStr($machine["status"]["nodeInfo"]["machineID"]));
    $phphashp1 = substr($phphash, 0, 12);
    $phphashp2 = substr($phphash, 18, 14);

    if($phphashp1 == $id128hashp1 && $phphashp2 == $id128hashp2)
    {
        $nodeName = $machine["metadata"]["name"];
        if($debug)
        {
            error_log($nodeName);
            error_log($machine["status"]["nodeInfo"]["machineID"]);
        }
        foreach($machine["status"]["conditions"] as $condition)
        {
            if($condition["type"] == "Ready" && $condition["status"] == "True")
            {
                if($debug)
                {
                    error_log("Node Ready");
                }
                if(!array_key_exists('rebootmanager/status', $machine["metadata"]["annotations"]) || $machine["metadata"]["annotations"]["rebootmanager/status"] != "Ready")
                {
                    if($debug)
                    {
                        error_log("Marking node as done rebooting");
                    }
                    $data = array(
                        'spec' => array(
                            'unschedulable' => null
                        ),
                        'metadata' => array(
                            'annotations' => array(
                                'rebootmanager/status' => 'Ready'
                            )
                        )
                    );
                    
                    $context = getStreamContext("PATCH", $data);
                    $url = $base . "/nodes/" . $nodeName;

                    # Update annotation
                    $handle = fopen($url, 'r', false, $context);
                    if ($handle === false) {
                        $e = error_get_last();
                        error_log($e['message'], $e['type']);
                        flush();
                        die();
                    }
                    $response = stream_get_contents($handle);
                    fclose($handle);
                }
                # Sucessfully recorded that the node is online
                http_response_code(200);
            }
        }
    }
}
flush();

?>
