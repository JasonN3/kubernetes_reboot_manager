# kubernetes_reboot_manager
## Summary
This is a [Fleet Lock server](https://github.com/coreos/zincati/blob/master/docs/images/zincati-fleetlock.png) that Zincati can use to schedule reboots similar to [airlock](https://github.com/coreos/airlock). In addition to the standard locking provided by airlock, this server will evict the node of all pods before approving the reboot. All tracking is done using the Kubernetes API so an extra database is not required.

Currently there are no configuration files for the server itself and only 1 node is allowed to reboot at a time regardless of its group.
 
## Installation
1. Run the following commands
```bash
kubectl apply -f kubernetes/deployment.yaml
kubectl get -n reboot-manager svc/reboot-manager
```
2. Go to each node and create the file /etc/zincati/config.d/reboot-manager.toml with the following content (replace {{ Ext-Service-IP }} with the External Service IP of the service in step 1. If one isn't assigned, edit the service so one will be assigned using your CNI):
```toml
# How to finalize update.
[updates]
# Update strategy which uses an external reboot coordinator (FleetLock protocol).
strategy = "fleet_lock"

# Base URL for the FleetLock service.
fleet_lock.base_url = "http://{{ Ext-Service-IP }}/"
```
3. Restart Zincati on each of the nodes
```bash
sudo systemctl restart zincati
```
If you have any nodes intentionally set as unchedulable, you will need to set that flag again after Zincati checks in.

## How it works
### Steady-State
This is the normal state where the node will check in every 5 minutes to say it doesn't have any updates pending.
1. Get a list of all nodes.
1. Find the node that checked in by looking for a node with a matching machineID.
1. If the annotation 'rebootmanager/status' does not exist or is not set to 'Ready', change the annotation to 'Ready' and make sure the node is schedulable.

### Pre-Reboot
This is when the node will request a lock so it can reboot.
1. Get a list of all nodes.
1. Find the node that checked in by looking for a node with a matching machineID. At the same time build a list of machines that are currently rebooting.
1. If there are other machines currently rebooting, return 404 (reboot denied) without doing anything.
1. Mark the node as unschedulable and change the annotation 'rebootmanager/status' to 'Rebooting'.
1. Refresh the node information and get a list of machines with the annotation 'rebootmanager/status=Rebooting'.
1. Check for any other nodes that set the annotation at the same time. The first node alphabetically will get priority and any other requests should set the annotation back to 'rebootmanager/status=Ready', mark the node as schedulable, and return 404 (reboot denied) until the first node is done rebooting.
1. Get all pods running on the node and send an eviction command so the pods will be moved to another node. The requests are spammed at the API. Since the API has a rate-limiter, some requests may be denied. The next time the node attempts to restart, more of the requests will go through until there are no more nodes.
1. If all nodes have been evicted, return 200 (reboot approved) to allow the reboot. If there are still pods running (evictions still in progress or API calls were rate limited), return 404 (reboot denied) until the rest of the pods are evicted.
