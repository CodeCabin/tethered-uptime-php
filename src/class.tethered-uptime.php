<?php 

namespace CodeCabin;

/**
 * Tethered uptime PHP module
 * 
 * Used to send status updates and custom system metrics to Tethered
 */

class TetheredUptime {
    /* API details */
    const API_URL = "https://tethered.app/app/api";
    const API_VERSION = 1;

    /* Request methods */
    const METHOD_GET = "GET";
    const METHOD_POST = "POST";
    const METHOD_DELETE = "DELETE";

    /* Supported sync flags, which will be dispatched when sync is called */
    const SYNC_FLAG_STATUS = 1;
    const SYNC_FLAG_METRICS = 2;

    /* Supported resource flags we can monitor */
    const METRIC_FLAG_CPU = 1;
    const METRIC_FLAG_MEMORY = 2;
    const METRIC_FLAG_DRIVE = 3;

    public $configuration;
    public $hooks;

    public $ready;

    /**
     * Constructor
     * 
     * Initializes Tethered with a configuation object
     * 
     * @param object $config Configuration options, which override the defaults if provided
     */
    public function __construct($config){
        $this->ready = false;
        $this->configure($config);

        if(!empty($this->configuration->apikey) && !empty($this->configuration->monitorId)){
            $this->ready = true;
            $this->trigger('ready');
        }
    }

    /**
     * Configure the instance with preferred runtime options 
     * 
     * Supported options: 
     * - apikey       : Tethered API key, located in the account information section on tethered
     * - monitorId    : The monitor id that you are sending data for, must be owned by the API key associated
     * - syncFlags    : The data types you'd like to send on sync. We recommend all, for machine monitors, and metrics only for other monitors like URL, PORT, etc
     * - metricFlags  : The system resources you'd like to monitor, you can still send manual resources, but these are included, see static variables
     * - modifiers    : If you need to mutate/add to our internal datasets you can use modifiers to listen for data and return your own. 
     *                  Object of key/value pairs, where key is event name, and value is either a callable function or an array of callable functions (if chaining is needed)
     * - events       : If you need to listen for our internal events, you can pass your listeners in here as part of the init call. 
     *                  Object of key/value pairs, where key is event name, and value is either a callable function or an array of callable functions (if chaining is needed)
     * 
     * Stores directly to instance, and keys must be predefined in the default configuration object
     * 
     * @param object $config Configuration options, which override the defaults if provided
     * 
     * @return void
     */
    public function configure($config){
        $this->hooks = (object) array(
            'modifiers' => (object) array(),
            'events' => (object) array()
        );

        $this->configuration = (object) array(
            'apikey' => false,
            'monitorId' => 0,
            'syncFlags' => array(self::SYNC_FLAG_STATUS, self::SYNC_FLAG_METRICS),
            'metricFlags' => array(self::METRIC_FLAG_CPU, self::METRIC_FLAG_MEMORY, self::METRIC_FLAG_DRIVE),
            'modifiers' => (object) array(),
            'events' => (object) array()
        );

        if(!empty($config)){
            if(is_array($config)){
                $config = (object) $config;
            }

            if(!empty($config)){
                foreach($config as $key => $value){
                    if(isset($this->configuration->{$key})){
                        if($key === 'events' || $key === 'modifiers'){
                            if(is_object($config->{$key})){
                                /* Event or modifiers as an object */
                                foreach($config->{$key} as $hookName => $hookValue){
                                    if(is_callable($hookValue)){
                                        switch($key){
                                            case 'modifiers':
                                                $this->addModifier($hookName, $hookValue);
                                                break;
                                            case 'events':
                                                $this->listen($hookName, $hookValue);
                                                break;
                                        }
                                    } else if(is_array($hookValue)){
                                        foreach($hookValue as $hookCallable){
                                            if(is_callable($hookCallable)){
                                                switch($key){
                                                    case 'modifiers':
                                                        $this->addModifier($hookName, $hookCallable);
                                                        break;
                                                    case 'events':
                                                        $this->listen($hookName, $hookCallable);
                                                        break;
                                                }
                                            }
                                        }
                                    }
                                }
                            }
                        } else if(is_object($this->configuration->{$key}) && is_object($value)){
                            /* Object passed, iterated and replace */
                            foreach($value as $subKey => $subValue){
                                if(isset($this->configuration->{$key}->{$subKey})){
                                    $this->configuration->{$key}->{$subKey} = $subValue;
                                }
                            }
                        } else {
                            /* Standard merge */
                            $this->configuration->{$key} = $value;
                        }
                    }
                }
            }
        }

        $this->trigger('configured', $this->configuration);
    }

    /**
     * Set the primry monitor that you are running this instance for 
     * 
     * Usually, you'd set this at instance creation, but you can alter it later
     * 
     * @param number $id The monitor id to target
     * 
     * @return void
     */
    public function setMonitor($id){
        $id = intval($id);
        if(!empty($id)){
            $this->configuration->monitorId = $id;
        }
    }

    /**
     * Sync all sync flags for the linked monitor
     * 
     * This will call the 'metrics' and 'status' methods, meaning both the uptime and system resources are synced 
     * 
     * You will still need to log any additional resources using our event triggers (see config), which allow you to hook into this sync method for your
     * own automation steps as/when needed
     * 
     * @return void
     */
    public function sync(){
        if(empty($this->ready)){
            return;
        }

        if(!empty($this->configuration->syncFlags)){
            if(in_array(self::SYNC_FLAG_STATUS, $this->configuration->syncFlags)){
                /* Configured to send status updates */
                $this->pushStatus();
            }

            if(in_array(self::SYNC_FLAG_METRICS, $this->configuration->syncFlags)){
                /* Configured to send metric updates */
                $this->pushMetrics();
            }
        }
        
        $this->trigger('sync');
    }

    /**
     * Send an uptime update via the API 
     * 
     * By default, it will be sent with a 200 status and a 0 time, but you can change these defaults with internal hooks 
     * 
     * @param int $code The status code to log
     * @param int $time The response/operation time to log
     * 
     * @return object
     */
    public function pushStatus($code = 200, $time = 0){
        if(empty($this->ready)){
            return;
        }

        $data = (object) array(
            'apikey' => $this->configuration->apikey,
            'id' => $this->configuration->monitorId,
            'status' => $this->applyModifiers('status.code', $code),
            'time' => $this->applyModifiers('status.time', $time)
        );

        $this->trigger('status');

        $status = $this->post('site/status', $data);
        $this->trigger('status.complete', $status);

        return $status;
    }

    /**
     * Log a custom metric to your site 
     * 
     * For one shot metrics users can call this one shot method instead of the automated metrics list method 
     * 
     * The alternative to this is to hook into the metrics methods instead and add to the list by returning additional metrics you want to log 
     * 
     * @param string $key The key slug for this metric
     * @param int|float $value The value of this metric
     * @param string $label The pretty printed label for this metric. Suffix can be passed as a quick tag, for example "Memory {{}}MB" would set :"MB" to be the suffix
     * @param string|int $type The type of metric you are storing. For example: counter, average, percentage etc
     * @param string|int $widget The type of widget you want to use for storage. For example: line, area, pie, donut, radar, heatmap
     * 
     * @return object
     */
    public function pushMetric($key, $value, $label = "", $type = "",  $widget = ""){
        if(empty($this->ready)){
            return;
        }

        if(!empty($key) && isset($value)){
            $data = (object) array(
                'apikey' => $this->configuration->apikey,
                'site' => $this->configuration->monitorId,
                'key' => $key,
                'value' => $value
            );

            if(!empty($label)){
                $data->label = $label;
            }

            if(!empty($type)){
                $data->type = $type;
            }

            if(!empty($widget)){
                $data->widget = $widget;
            }

            $this->trigger('metrics');

            $metric = $this->post('metrics/', $data);
            $this->trigger('metrics.complete', $metric);

            return $metric;
        }
        return false;
    }

    /**
     * Get the current system resource usage data 
     * 
     * This will call the snapshot method, and then filter the returned data after the fact
     * 
     * Once received, send it via the API 
     * 
     * @return object
     */
    public function pushMetrics(){
        if(empty($this->ready)){
            return;
        }

        $snapshot = $this->snapshot();
        if(!empty($snapshot)){
            $list = array();

            if(in_array(self::METRIC_FLAG_CPU, $this->configuration->metricFlags)){
                if(isset($snapshot->cpu)){
                    $list[] = (object) array(
                        "key" => "cpu",
                        "value" => $snapshot->cpu,
                        "label" => "CPU",
                        "type" => "percentage",
                        "widget" => "donut"
                    );
                }
            }

            if(in_array(self::METRIC_FLAG_MEMORY, $this->configuration->metricFlags)){
                if(isset($snapshot->memory)){
                    $list[] = (object) array(
                        "key" => "memory",
                        "value" => $snapshot->memory,
                        "label" => "Memory {{}}MB",
                        "type" => "average",
                        "widget" => "area"
                    );
                }
            }

            if(in_array(self::METRIC_FLAG_DRIVE, $this->configuration->metricFlags)){
                if(isset($snapshot->disk)){
                    $list[] = (object) array(
                        "key" => "disk_primary",
                        "value" => $snapshot->disk->capacity,
                        "label" => "Disk {$snapshot->disk->name}",
                        "type" => "percentage",
                        "widget" => "pie"
                    );
                }
            }

            $list = $this->applyModifiers('metrics.list', $list);
            if(!empty($list)){
                $data = (object) array(
                    'apikey' => $this->configuration->apikey,
                    'site' => $this->configuration->monitorId,
                    'list' => json_encode($list)
                );

                $this->trigger('metrics');

                $metrics = $this->post('metrics/', $data);
                $this->trigger('metrics.complete', $metrics);

                return $metrics;

            }
        }

        return false;
    }

    /**
     * Get list of monitors linked to your account
     * 
     * This will include some surface level data, which might be helpful for determining your own internal actions
     * 
     * This does not return the data, but instead dispatches the data via an event 
     * 
     * @return object
     */
    public function getMonitors(){
        if(empty($this->ready)){
            return;
        }

        $data = (object) array(
            'apikey' => $this->configuration->apikey,
        );

        $this->trigger('monitors');

        $sites = $this->get('sites/', $data);
        $this->trigger('monitors.complete', $sites);

        return $sites;
    }

    /**
     * Get list of incidents linked to your account
     * 
     * This will return paginated results, meaning you can pass a page paramater
     * 
     * @param int $page The page to be loaded, if left empty, will default to 1
     * 
     * @return object
     */
    public function getIncidents($page = 1){
        if(empty($this->ready)){
            return;
        }

        $page = !empty($page) && !empty(intval($page)) ? intval($page) : 1;

        $data = (object) array(
            'apikey' => $this->configuration->apikey,
            'page' => $page
        );

        $this->trigger('incidents');

        $incidents = $this->get('incidents/', $data);
        $this->trigger('incidents.complete', $incidents);

        return $incidents;
    }

    /**
     * Create an incident
     * 
     * You can also update an incident, but for this, you should use the 'post' method and package the request yourself instead of using this helper
     * 
     * @param string $title The title of the incident
     * @param string $description The description of the incident
     * @param string $source The source of the incident, for example "NodeJS Server". Will default to "api" if not set
     * @param int $status The status to set this to, defaults to 0 (ongoing)
     * 
     * @return object
     */
    public function pushIncident($title, $description, $source = false, $status = 0){
        if(empty($this->ready)){
            return;
        }

        if(!empty($title) && !empty($description)){
            $data = (object) array(
                'apikey' => $this->configuration->apikey,
                'siteid' => $this->configuration->monitorId,
                'incident_title' => $title,
                'data_description' => $description
            );

            if(!empty($source)){
                $data->incident_source = $source;
            }

            if(!empty($status)){
                $data->status = $status;
            }

            $this->trigger('incident');

            $incident = $this->post('incident/', $data);
            $this->trigger('incident.complete', $incident);

            return $incident;
        }
        return false;
    }

     /**
     * Snapshot system resources, to be sent via the API 
     * 
     * The promise will resolve with the current metric data, which is then filtered down by your preferred resource flags
     * 
     * @return object
     */
    public function snapshot(){
        $snapshot = (object) array(
            'memory' => $this->getMemory(),
            'cpu' => $this->getCPU(),
            'disk' => $this->getDisk()
        );

        return $this->applyModifiers('snapshot', $snapshot);
    }

    /**
     * Register a modifiers to the instance
     * 
     * This allows additional extension or mutation of data before it is used by the instance 
     * 
     * Each instance is queued to the tag, meaning they can be stacked/chained together
     * 
     * @param string $tag The event tag you want to hook into and modify packet data for
     * @param callable $callable The function/callable to send the data to, remember this callable must return the data back when called on
     * 
     * @return void
     */
    public function addModifier($tag, $callable){
        if(!isset($this->hooks->modifiers->{$tag}) || !is_array($this->hooks->modifiers->{$tag})){
            $this->hooks->modifiers->{$tag} = array();
        }

        if(!empty($callable)){
            $this->hooks->modifiers->{$tag}[] = $callable;
        }
    }

    /**
     * Apply modifiers based on an event tag
     * 
     * This will loop over each registered modifier, call it and update the data packet, allowing chaining
     * 
     * Those callables MUST return the data as it will eventually end up back in the instance
     * 
     * @param string $tag The event tag being processed
     * @param mixed $data The data being processed, which can be altered by the callbacks in the queue
     * 
     * @return mixed
     */
    public function applyModifiers($tag, $data) {
        if(!empty($this->hooks->modifiers->{$tag})){
            foreach($this->hooks->modifiers->{$tag} as $callable){
                if(is_callable($callable)){
                    $args = !empty($data) ? array($data) : array();

                    $data = call_user_func_array($callable, $args);
                }
            }
        }
        return $data;
    }

    /**
     * Register an event listener, which this instance will call 
     * 
     * Callable is linked to the tag, in a queue, meaning you can link multiple listeners to the same event 
     * 
     * These do not allow you to mutate/return data, in other words, it is a one-way event
     * 
     * If you need that, look at modifiers
     * 
     * @param string $tag The event tag to listen for
     * @param callable $callable The callable to be run when the event is fired
     * 
     * @return void
     */
    public function listen($tag, $callable){
        if(!isset($this->hooks->events->{$tag}) || !is_array($this->hooks->events->{$tag})){
            $this->hooks->events->{$tag} = array();
        }

        if(is_callable($callable)){
            $this->hooks->events->{$tag}[] = $callable;
        }
    }

    /**
     * Trigger an event internally within this instance 
     * 
     * This will fire off all of the registered event listeners, allowing implementations to take additional actions based on the instance events 
     * 
     * @param string $tag The event tag to trigger
     * @param mixed $data Any data to be sent to listeners 
     * 
     * @return void
     */
    public function trigger($tag, $data = false) {
        if(!empty($this->hooks->events->{$tag})){
            foreach($this->hooks->events->{$tag} as $callable){
                if(is_callable($callable)){
                    $args = !empty($data) ? array($data) : array();
                    call_user_func_array($callable, $args);
                }
            }
        }
    }

    /**
     * Make a GET request to the API 
     * 
     * @param string $endpoint Target endpoint
     * @param object $data Data to send to the endpoint, must include any needed auth details 
     * 
     * @return object
     */
    public function get($endpoint, $data = false){
        return $this->request($endpoint, $data, self::METHOD_GET);
    }

    /**
     * Make a POST request to the API 
     * 
     * @param string $endpoint Target endpoint
     * @param object $data Data to send to the endpoint, must include any needed auth details 
     * 
     * @return object
     */
    public function post($endpoint, $data = false){
        return $this->request($endpoint, $data, self::METHOD_POST);
    }

    /**
     * Make a DELETE request to the API 
     * 
     * @param string $endpoint Target endpoint
     * @param object $data Data to send to the endpoint, must include any needed auth details 
     * 
     * @return object
     */
    public function delete($endpoint, $data = false){
        return $this->request($endpoint, $data, self::METHOD_DELETE);
    }

    /**
     * Make a request request to the API 
     * 
     * @param string $endpoint Target endpoint
     * @param object $data Data to send to the endpoint, must include any needed auth details
     * @param string $method The method to use for this request 
     * 
     * @return object
     */
    public function request($endpoint, $data = false, $method = self::METHOD_GET){
        $packed = (object) array(
            'success' => false,
        );

        try{ 
            $endpoint = trim($endpoint);
            if(!empty($endpoint)){
                $url = array(self::API_URL, "v" . self::API_VERSION, $endpoint);
                $url = implode("/", $url);

                $handle = curl_init();

                switch($method){
                    case self::METHOD_GET:
                    case self::METHOD_DELETE:
                        if(!empty($data) && (is_object($data) || is_array($data)) ){
                            $data = http_build_query($data);
                        }

                        $url = !empty($data) ? "{$url}?{$data}" : $url;
                        break;
                    case self::METHOD_POST:
                        curl_setopt($handle, CURLOPT_POST, 1);

                        if(!empty($data) && (is_object($data) || is_array($data)) ){
                            curl_setopt($handle, CURLOPT_POSTFIELDS, json_encode($data));
                        }
                        break;
                }

                curl_setopt($handle, CURLOPT_URL, $url);
                curl_setopt($handle, CURLOPT_RETURNTRANSFER, true);

                $handle = $this->applyModifiers('request.curlhandle', $handle);

                $this->trigger('request', (object) array( 'url' => $url, "endpoint" => $endpoint, "payload" => $data));

                $result = curl_exec($handle);
                $status = curl_getinfo($handle, CURLINFO_HTTP_CODE);

			    curl_close ($handle);


                if(!empty($result)){
                    try{ 
                        $json = json_decode($result);
                        $packed->data = $json;
                    } catch(\Exception $jsonEx){

                    } catch(\Error $jsonError){
                        
                    }
                }

                $packed->status = $status;
                if($status < 400){
                    /* Success */
                    $packed->success = true;
                }

            }
        } catch(\Exception $ex){
            $packed->error = $ex->getMessage();
        } catch(\Error $error) {
            $packed->error = $error->getMessage();
        }

        $this->trigger('request.complete', $packed);

        return $packed;
    }
    
    /**
     * Get memory usage and convert it to MB value
     * 
     * This uses memory_get_usage to get data samples and then converts it, before returning it to the caller
     * 
     * Usually the snapshot method
     * 
     * @return int
     */
    private function getMemory(){
        $memory = memory_get_usage(true);
        $memory = !empty($memory) ? ($memory / 1024 /1024) : 0;
        return $memory;
    }

    /**
     * Get CPU usage for either windows or linux using either a file read or a command line execution
     * 
     * For windows, we use a command and extract/parse the result for CPU usage 
     * 
     * For linux, we perform a proc/stat read 1 second apart and calculate deltas 
     * 
     * Solution adapted from: https://www.php.net/manual/en/function.sys-getloadavg.php#118673
     * 
     * Returns value as a percentage, usually to the snapshot method
     * 
     * @return float
     */
    private function getCPU(){
        $cpu = null;

        if(stristr(PHP_OS, "win")){
            try{
                $command = "wmic cpu get loadpercentage /all";
                exec($command, $commandOutput);

                if ($commandOutput){
                    foreach ($commandOutput as $line){
                        if ($line && preg_match("/^[0-9]+\$/", $line)){
                            $cpu = $line;
                            break;
                        }
                    }
                }
            } catch(\Exception $ex){

            } catch(\Error $error){

            }
        } else {
            if (is_readable("/proc/stat")){
                
                $stats = (object) array();

                $stats->a = $this->getCPULinux();
                sleep(1);
                $stats->b = $this->getCPULinux();

                if(!empty($stats->a) && !empty($stats->b)){
                    // Get difference
                    $stats->b[0] -= $stats->a[0];
                    $stats->b[1] -= $stats->a[1];
                    $stats->b[2] -= $stats->a[2];
                    $stats->b[3] -= $stats->a[3];

                    // Sum up the 4 values for User, Nice, System and Idle and calculate
                    // the percentage of idle time (which is part of the 4 values!)
                    $cpuTime = $stats->b[0] + $stats->b[1] + $stats->b[2] + $stats->b[3];

                    // Invert percentage to get CPU time, not idle time
                    $cpu = 100 - ($stats->b[3] * 100 / $cpuTime);
                }
            }
        }
        
        return !empty($cpu) ? floatval($cpu) : 0;
    }

    /**
     * Linux specific CPU usage fetcher, should be called in two samples to calculate
     * the differences, see getCPU for more details 
     * 
     * Solution adapted from: https://www.php.net/manual/en/function.sys-getloadavg.php#118673
     * 
     * @return array
     */
    private function getCPULinux(){
        if (is_readable("/proc/stat")){
            try {
                $stats = file_get_contents("/proc/stat");

                if(!empty($stats)){
                    // Remove double spaces to make it easier to extract values with explode()
                    $stats = preg_replace("/[[:blank:]]+/", " ", $stats);

                    // Separate lines
                    $stats = str_replace(array("\r\n", "\n\r", "\r"), "\n", $stats);
                    $stats = explode("\n", $stats);

                    foreach ($stats as $statLine){
                        $statLineData = explode(" ", trim($statLine));
                        if((count($statLineData) >= 5) && ($statLineData[0] == "cpu")){
                            return array(
                                $statLineData[1],
                                $statLineData[2],
                                $statLineData[3],
                                $statLineData[4],
                            );
                        }
                    }

                }
            } catch(\Exception $ex){

            } catch(\Error $error){

            }
        }
        return null;
    }

    /**
     * Get disk capacity usage for the relevant system type
     * 
     * This is done by leveraging 'disk_free_space' and 'disk_total_space' and performing some calculations to confirm usage
     * 
     * You can filter the target directory for this using the 'disk.directory' modifier. By default, it will use C: for windows
     * and / for linux
     * 
     * Returns an object with the percentage usage and name of the directory
     * 
     * @return object
     */
    private function getDisk(){
        $directory = $this->applyModifiers('disk.directory', stristr(PHP_OS, "win") ? "C:" : "/");

        $free = disk_free_space($directory);
        $total = disk_total_space($directory);

        $percentage = (($total - $free) / $total) * 100;
        return (object) array(
            "name" => $directory === "/" ? "root" : $directory,
            "capacity" => floatval($percentage)
        );
    }
}