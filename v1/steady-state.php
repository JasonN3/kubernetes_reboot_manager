<?php
# steady-state.php
# Called every 5 minutes by Zincati

$debug = False;

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
    throw new \Exception($e['message'], $e['type']);
}
$response = stream_get_contents($handle);
fclose($handle);

$response = json_decode($response, true);

# Find the node that made the request
foreach($response["items"] as $machine)
{
    if($machine["status"]["nodeInfo"]["machineID"] == $request["client_params"]["id"])
    {
        $nodeName = $machine["metadata"]["name"];
        if($debug)
        {
            echo $nodeName . "\r\n";
            echo $machine["status"]["nodeInfo"]["machineID"] . "\r\n";
        }
        foreach($machine["status"]["conditions"] as $condition)
        {
            if($condition["type"] == "Ready" && $condition["status"] == "True")
            {
                if($debug)
                {
                    echo "Node Ready\r\n";
                }
                if(!array_key_exists('rebootmanager/status', $machine["metadata"]["annotations"]) || $machine["metadata"]["annotations"]["rebootmanager/status"] != "Ready")
                {
                    if($debug)
                    {
                        echo "Marking node as done rebooting\r\n";
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
                        throw new \Exception($e['message'], $e['type']);
                    }
                    $response = stream_get_contents($handle);
                    fclose($handle);
                }
            }
        }
    }
}


?>
