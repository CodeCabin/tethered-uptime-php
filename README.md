# Tethered - Uptime monitoring
Integrate Tethered into your PHP projects with our uptime monitoring. 

You can send custom metrics, log uptime internally and create incidents. 

## Requirements
Before getting started, you will need a [Tethered](https://tethered.app/) account, and your API key found within your [account information.](https://tethered.app/app/account)

You will also need a monitor setup, as this is required sync calls. This can be changed using a helper method, but in most cases your server would be linked to a specific monitor id. 

## Inclusion
If you are using [Composer](https://getcomposer.org/) our library should be available as a from [Packagist](https://packagist.org/), and can be installed by first adding the dependency to our module in your `composer.json`
```
{
  "require": {
      "codecabin/tethered-uptime-php": "*"
  }
}
```

And then installing dependencies using the following command: 
```
php composer.phar install
```

Our module will then be loaded automatically when you include your vendor autoloader:
```
require_once __DIR__ . '/vendor/autoload.php';
```

Or, if preferred you can perform the installation with a single command instead:
```
php composer.phar require codecabin/tethered-uptime-php:*
```

---

If you are not using composer as your dependency manager, you can also download this project and load the file normally instead: 
```
require_once(__DIR__ . '/tethered-uptime-php/src/class.tethered-uptime.php');
```

For ease of use, you may also want to use our namespace to make declaration simpler:
```
use CodeCabin\TetheredUptime;
```

For the remaining examples, we will assume you have included this in your project.

## Basic Usage
If you'd like to send system metrics automatically along with your uptime, this can be achieved with just a few lines of code:
```
$uptime = new TetheredUptime(
    (object) array(
        'apikey' => '[APIKEY]', 
        'monitorId' => 1,
    )
);
```

For PHP we cannot automatically run sync logic on a programmatic cronjob, as such, you **will need to manage** how often the sync call is made. Usually you would setup your own **cronjob** and initialize this instance there, calling sync at a defined frequency:
```
$uptime->sync();
```

## Configuration Options

As part of our module constructor, you can pass a configuration object, as demonstrated in the **Basic Usage** example:
```
$uptime = new TetheredUptime($configuration);
```

This object supports many options which you can use to change the way our module behaves. 

| Key | Type | Value |
|-----|------|-------|
| apikey       | string | Tethered API key, located in the account information section on tethered |
| monitorId    | int | The monitor id that you are sending data for, must be owned by the API key associated, can be changed after initialization using helper method |
| syncFlags    | array(int) | The data types you'd like to send on sync. We recommend all (default), for machine monitors, and metrics only for other monitors like URL, PORT, etc. See SYNC_FLAG constants |
| metricFlags  | array(int) | The system resources you'd like to monitor, you can still send manual resources, but these are included by default, see METRIC_FLAG constants |
| modifiers    | object | If you need to mutate/add to our internal datasets you can use modifiers to listen for data and add/replace the dataset. Object of key/value pairs, where key is event name, and value is either a callable function or an array of callable functions (if chaining is needed)
| events       | object | If you need to listen for our internal events, you can pass your listeners in here as part of the init call. Object of key/value pairs, where key is event name, and value is either a callable function or an array of callable functions (if chaining is needed)

## Constants
Let's take a look at each of the available constants which you can use as part of your configuration. 

| Primary | Secondary | Value |
|---------|-----------|-------|
| API_URL | | Our API URL | 
| API_VERSION | | Version of the API to use | 
| SYNC_FLAG_{TYPE} | | | 
| | SYNC_FLAG_STATUS | 1 - Status sync |
| | SYNC_FLAG_STATUS | 2 - Metrics sync |
| METRIC_FLAG_{TYPE} | | |
| | METRIC_FLAG_CPU | 1 - CPU usage metrics | 
| | METRIC_FLAG_MEMORY | 2 - Memory usage metrics |
| | METRIC_FLAG_DRIVE | 3 - Drive capacity metrics | 
| METHOD_{TYPE} | | | 
| | METHOD_GET | GET Request method |
| | METHOD_POST | POST Request method |
| | METHOD_DELETE | DELETE Request method |

## Modifiers 
Using modifiers to alter the data sent to Tethered can be helpful, for example, if you'd like to send an additional resource statistic, but also want to optimize your usage of our API (where some rate limits apply), or simply want to include this data whenever you call the 'sync' method.

Let's take a look at how you might hook into the metrics list which is sent on 'sync' to include your own data:
```
$uptime = new TetheredUptime(
    (object) array(
        'apikey' => '[APIKEY]', 
        'monitorId' => 1,
        'modifiers' => (object) array(
            'metrics.list' => array(
                function($list) {
                    $list[] = (object) array(
                        'key' => 'custom.metric',
                        'value' => 5,
                        'label' => 'Custom Metric',
                        'type' => 'percentage',
                        'widget' => 'pie'
                    );

                    return $list;
                }
            )
        )
    )
);

$uptime->sync();
```

There is another way to apply a modifier, which is similar to other hook based systems: 
```
$uptime = new TetheredUptime($configuration);

$uptime->addModifier('status.code', function($code){
    return $code * 2;
});

$uptime->sync();
```

### Modifiers Available
Here's a list of the currently available modifiers, along with the paramater type each of these will pass. These will likely be expanded with time. 

| Tag | Type | Description |
|-----|------|-------------|
| status.code | int | Part of 'pushStatus' method, represents a HTTP status code (Default: 200) |
| status.time | int | Part of 'pushStatus' method, represents time in milliseconds (Default: 0 ) | 
| metrics.list | array | Part of 'pushMetrics' method, represents all metrics that are about to be synced | 
| snapshot | object | Part of 'snapshot' method, represents the system resources, which are used in 'pushMetrics' | 
| request.curlhandle | handle | Part of the 'request' method, the curl handle, allowing you to change the request options fully | 
| disk.directory  | string | Part of the 'getDisk' method, represents the default directory target

## Events 
Events are another way of observing events within the module as they are dispatched. These are different from modifiers as our module does not wait for or expect any response, meaning this is a one-way event. 

These can also be registered in two ways, in the same way as modifiers, so we will look at both of these now. 

Firstly, let's listen for the 'sync' event, by adding our listener directly to the configuration object. 
```
$uptime = new TetheredUptime((object) array(
    'apikey' => '[APIKEY]', 
    'monitorId' => 1,
    'events' => (object) array(
        'sync' => array(
            function() {
                echo "Sync has been called";
            }
        )
    )
));

$uptime->sync();
```

Now we'll take a look at listening for a specific response event, which runs when a status is logged successfully. This also includes the response object, and for demonstration purposes, we'll register this listener after initialization: 

```
$uptime = new TetheredUptime($configuration);

$uptime->listen('status.complete', function($response){
    echo "Response was received: <br>";
    var_dump($response);
});

$uptime->sync();
```

### Events Available
Here's a list of our available events, along with the type of data it will send, if any. These will likely be expanded with time. 

| Tag | Type | When |
|-----|------|-------------|
| ready | | After the instance initializes, if API key and monitor ID is set in the config (required config fields) |
| configured | object | Final step of our 'configure' method, after the configuration object is applied, before flagging instance as 'ready' |
| sync | | During sync call, after calling the flagged sync methods |
| status | | Before status is sent to the API |
| status.complete | object | After status has been sent to the API, passes the response object |
| metrics | | Before metrics are sent to the API, for both single or list |
| metrics.complete | object | After status has been sent to the API, passes the response object, for both single or list |
| monitors | | Before monitors are fetched from the API, requires a manual call, we don't use this method automatically |
| monitors.complete | object | After the monitors list has been returned by the API, passes the response from the API |
| incidents | | Before incidents are fetched from the API, requires a manual call, we don't use this method automatically |
| incidents.complete | object | After the incidents list has been returned by the API, passes the response from the API |
| incident | | Before an incident creation call is made to the API, requires a manual call, we don't use this method automatically |
| incident.complete | object | After an incident creation call has been made to the API, passes the response from the API |
| request | object | Before a request is made, not linked to any specific method, passes details about the request | 
| request.complete | object | After a request is made, passes the response from the API | 

## Methods
The following section will cover all of the methods available in the module. Some of these are specifically for internal use, and as such will not be demonstrated, as calling these is not suggested.

### configure($config)
Configures the module, as part of the constructor call. Configuration object is synced with an internal default and any passed modifiers and event listeners are registered. 

### setMonitor($id)
Allows you to adjust the active target monitor after initialization, if needed for multi-monitor management. 

```
$uptime = new TetheredUptime($configuration);

// Set active monitor to ID 2
$uptime->setMonitor(2);
```

### sync() 
Automatically sends all data as controlled by $configuration->syncFlags to the server, usually status and metrics. This should be called in some event you control, for example a cronjob that you have defined to run every hour.

```
$uptime = new TetheredUptime($configuration);

// Trigger the sync event
$uptime->sync();
```

### pushStatus($code, $time)
Push a new status code for your active monitor to the API. This is automatically called by the sync() method, but can also be called manually if needed.

Returns the result of the underlying request, if needed.

```
$uptime = new TetheredUptime($configuration);

// Send status code 403 with timing of 112ms
$response = $uptime->pushStatus(403, 112);
```

### pushMetric($key, $value, $label = "", $type = "", $widget = "")
Push a single metric for your active monitor to the API. This is not automatically called as we instead use the pushMetrics() method which pulls a snapshot of the system

Returns the result of the underlying request, if needed.

```
$uptime = new TetheredUptime($configuration);

// Log a custom metric (single)
$response = $uptime->pushMetric('custom_metric', 5, 'Custom Metric', 'percentage', 'pie');
```

### pushMetrics()
Push all metrics, controlled by $configuration->metricFlags, by using the snapshot method, to the API. This is automatically called by the sync method, but can also be called manually if needed.

Need to add a custom metric to this bulk push? Take a look at modifiers. 

Returns the result of the underlying request, if needed. 

```
$uptime = new TetheredUptime($configuration);

// Log all metrics (all)
$response = $uptime->pushMetrics();
```

### getMonitors() 
Get your full monitor list from the API. This is not called automatically, and is a helper for you to use if needed. Results are not paginated, so bear this in mind. 

Returns the result of the underlying request, which will include your monitors. 

```
$uptime = new TetheredUptime($configuration);

// Get monitors
$monitors = $uptime->getMonitors();
```

### getIncidents($page = 1) 
Get incidents linked to your account. This is not called automatically, and is a helper for you to use if needed. Results are paginated.

Returns the result of the underlying request, with the incidents for the selected page.

```
$uptime = new TetheredUptime($configuration);

// Get incidents
$incidents = $uptime->getIncidents();
```

### pushIncident($title, $description, $source = false, $status = 0)
Create a new incident linked to your account, this will be linked to your active monitor. This is not called automatically, and is for you to use as needed

Returns the result of the underlying request, if needed. 

```
$uptime = new TetheredUptime($configuration);

// Create an incident
$response = $uptime->pushIncident("Server issue", "PHP server is experiencing issues, with these details...", "PHP Server", 0);
```

### snapshot()
Get a snapshot of the system resources. This is called during the sync call, if syncing metrics

```
$uptime = new TetheredUptime($configuration);

// Get resource snapshot
$snapshot = $uptime->snapshot();
```

### addModifier($tag, $callable)
Add a modifier to the modifier list, linked to a specific tag (hook) with a callable function. 

```
$uptime = new TetheredUptime($configuration);

// Add a modifier
$uptime->addModifier('status.code', function($code){
    $code = 403;
    return $code;
});
```

### applyModifiers($tag, $data)
Apply a modifier within the instance, this will call the tag and loop over any linked callables (chained) and allow each of them to mutate the data, before returning the final sample back to the module to be used. 

This is an internal method, we don't recommend using it outside of the module as it's for internal use, but it is theoretically possible to do so.

### listen($tag, $callable)
Add an event listener to the module, linked to a specific tag (hook) with a callable function. 

```
$uptime = new TetheredUptime($configuration);

// Add a listener
$uptime->listen('ready', function() {
    echo "scheduler is running";
});
```

### trigger($tag, $data)
Trigger an event within the instance, this will call the tag and loop over any linked callables and send any packet data via the function call. This is a one way event, you cannot return any data. 

This is an internal method, we don't recommend using it outside of the module as it's for internal use, but it is theoretically possible to do so.

### get($endpoint, $data)
Perform a GET request to our API with the **endpoint** and **data** as required by the API. 

This will return the request response, and can be used to perform any API call that is not already supported by the helper methods. 

Remember that you do need to pass your API key as part of the data when calling directly. 

```
$uptime = new TetheredUptime($configuration);

$data = (object) array(
    "apikey" => $uptime->configuration->apikey
);

// Get notifiers linked to your account
$response = $uptime->get('/notifiers');
```

### post($endpoint, $data)
Perform a POST request to our API with the **endpoint** and **data** as required by the API. 

This will return the request response, and can be used to perform any API call that is not already supported by the helper methods. 

Remember that you do need to pass your API key as part of the data when calling directly. 

```
$uptime = new TetheredUptime($configuration);

$data = (object) array(
    "apikey" => $uptime->configuration->apikey,
    /* Additional fields required and specified by API */
);

// Create notifier linked to your account
$response = $uptime->post('/notifier');
```

### delete(endpoint, data)
Perform a DELETE request to our API with the **endpoint** and **data** as required by the API. 

This will return the request response, and can be used to perform any API call that is not already supported by the helper methods. 

Remember that you do need to pass your API key as part of the data when calling directly. 

```
$uptime = new TetheredUptime($configuration);

$data = (object) array(
    "apikey" => $uptime->configuration->apikey,
    "id" => 1
);

// Delete notifier linked to your account
$response = $uptime->delete('/notifier');
```

### request($endpoint, $data, $method)
Final request method, for internal use, and actually compiles the request before sending it to the API. 

You should use **get**, **post** or **delete** instead of calling this directly.

## API 
Remember that you can call any of our API endpoints, as long as you have access to that specific feature and are within your usage limits, using the get, post, and delete methods, this means that this module does allow for almost full account automation if that is something you need. 

You will need quite a comprehensive understanding of our API, but you can learn more about that in our [developer documentation](https://tethered.app/documentation/).