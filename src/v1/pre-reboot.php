<?php
# pre-reboot.php
# Called before a reboot by Zincati
# 404 = reboot denied
# 200 = reboot approved

$debug = false;

# Create stream context for API calls
## $verb = GET, POST, or PATCH
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
    if($verb == "POST")
    {
        $opts['http']['header'] .= "Content-Type: application/json\r\n";
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
$server = 'https://kubernetes.default.svc';
$base = $server . '/api/v1';

# Set options for Kubernetes API call
$context = getStreamContext();

# Create url string 
$url = $base . '/nodes';

# Get nodes
$handle = fopen($url, 'r', false, $context);
if ($handle === false) {
    $e = error_get_last();
    error_log($e['message'], $e['type']);
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


# Find all nodes that are currently rebooting (add ourselves to the list)
$machinesRebooting = array();
$nodeName = "";
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
            echo $nodeName . "\r\n";
            echo $machine["status"]["nodeInfo"]["machineID"] . "\r\n";
            echo $machine["metadata"]["annotations"]["rebootmanager/status"] . "\r\n";
        }
        $machinesRebooting[] = $nodeName;
        continue;
    }
    if($machine["metadata"]["annotations"]["rebootmanager/status"] == "Rebooting")
    {
        $machinesRebooting[] = $machine["metadata"]["name"];
    }
}

# Check if other nodes are currently rebooting
if(count($machinesRebooting) > 1)
{
    if($debug)
    {
        echo "Multiple machines attempting to restart\r\n";
        echo "Reboot delayed. Other nodes rebooting\r\n";
        var_dump($machinesRebooting);
    }
    $result = array("kind" => 'f1', "value" => 'other nodes rebooting');
    echo json_encode($result);
    http_response_code(404);
    exit();
}

# Mark the node as rebooting
$data = array(
    'spec' => array(
        'unschedulable' => true
    ),
    'metadata' => array(
        'annotations' => array(
            'rebootmanager/status' => 'Rebooting'
        )
    )
);

$context = getStreamContext("PATCH", $data);
$url = $base . "/nodes/" . $nodeName;

## Update annotation
$handle = fopen($url, 'r', false, $context);
if ($handle === false) {
    $e = error_get_last();
    error_log($e['message'], $e['type']);
}
fclose($handle);


$context = getStreamContext();
$url = $base . '/nodes';

# Refresh node information
$handle = fopen($url, 'r', false, $context);
if ($handle === false) {
    $e = error_get_last();
    error_log($e['message'], $e['type']);
}
$response = stream_get_contents($handle);
fclose($handle);

$response = json_decode($response, true);
$machinesRebooting = array();

# Find the nodes that are marked for rebooting
foreach($response["items"] as $machine)
{
    if($machine["metadata"]["annotations"]["rebootmanager/status"] == "Rebooting")
    {
        $machinesRebooting[] = $machine["metadata"]["name"];
    }
}

sort($machinesRebooting);
# Ensure this is the node that should be rebooting
if($machinesRebooting[0] == $nodeName)
{
    if($debug)
    {
        echo "We're first in line. Continue with reboot preparations.\r\n";
    }
}
else
{
    if($debug)
    {
        echo "Reboot delayed. Other nodes rebooting\r\n";
    }
    # Remove rebooting annotation
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

    ## Update annotation
    $handle = fopen($url, 'r', false, $context);
    if ($handle === false) {
        $e = error_get_last();
        error_log($e['message'], $e['type']);
    }
    fclose($handle);
    $result = array("kind" => 'f1', "value" => 'other nodes rebooting');
    echo json_encode($result);
    http_response_code(404);
    exit();
}

# Evict all nodes
## Get all pods running on the node
$url = $base . '/pods?fieldSelector=spec.nodeName%3D' . $nodeName;
$context = getStreamContext();

$handle = fopen($url, 'r', false, $context);
if ($handle === false) {
    $e = error_get_last();
    error_log($e['message'], $e['type']);
}
$response = stream_get_contents($handle);
fclose($handle);

$response = json_decode($response, true);
foreach($response["items"] as $pod)
{
    if($pod["metadata"]["ownerReferences"][0]["kind"] != "DaemonSet")
    {
        if($debug)
        {
            echo "Evicting " . $pod["metadata"]["name"];
        }
        $data = array(
            'apiVersion' => "policy/v1beta1",
            'kind' => "Eviction",
            'metadata' => array(
                'name' => $pod["metadata"]["name"],
                'namespace' => $pod["metadata"]["namespace"]
            )
        );
        $url = $server . $pod["metadata"]["selfLink"] . '/eviction';
        $context = getStreamContext("POST", $data);
        $handle = fopen($url, 'r', false, $context);
        # Don't worry about errors. If the evictions are submitted too fast, the API will deny some of them.
        # On the next reboot attempt, it will clean up more.
        fclose($handle);
    }
}

# Check if all pods are done running
$url = $base . '/pods?fieldSelector=spec.nodeName%3D' . $nodeName;
$context = getStreamContext();

$handle = fopen($url, 'r', false, $context);
if ($handle === false) {
    $e = error_get_last();
    error_log($e['message'], $e['type']);
}
$response = stream_get_contents($handle);
fclose($handle);

$response = json_decode($response, true);
foreach($response["items"] as $pod)
{
    if($pod["metadata"]["ownerReferences"][0]["kind"] != "DaemonSet")
    {
        if($debug)
        {
            echo "There are pods still running";
        }
        $result = array("kind" => 'f1', "value" => 'pods still evicting');
        echo json_encode($result);
        http_response_code(404);
        exit();
    }
}

?>
