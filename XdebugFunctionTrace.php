<?php
/**
 * @info XdebugFunctionTrace : class to trace invoked functions between a code block
 * @note : Xdebug extension is required for this class
 * @references :
 * - Xdebug : https://xdebug.org/
 * - Xdebug trace : https://xdebug.org/docs/trace
 * - Usefull repo : https://github.com/splitbrain/xdebug-trace-tree
 * @auther Saiyed M. Jasir M. Nisar
 */
class XdebugFunctionTrace {
    public $rootPath = '';
    public $traceFilePath = '';
    public $checkEnvToEnable = false;

    /**
     * @info : starts xdebug trace
     * @return void|bool
     */
    public function xdebugStartTrace() {
        if ($this->checkEnvToEnable && !(boolean)getenv('ENABLE_XDEBUG_FUNCTION_TRACE')) {
            return false;
        }
        ini_set('xdebug.trace_format', '1'); // for computer generated version of trace file
        /**
         * @param string $traceFile | default = null
         * @param int|bitfield $options | default = 0
         * - here $options = 12
         * - i.e. XDEBUG_TRACE_HTML (4) + XDEBUG_TRACE_NAKED_FILENAME (8)
         * - Ref : navigate to this link https://xdebug.org/docs/all_functions then search 'xdebug_start_trace'
         */
        // var_dump($this->getXdebugFunctionTraceFilePath());die;
        xdebug_start_trace($this->getXdebugFunctionTraceFilePath(), 8);
    }

    /**
     * @info : get filtered XDebug function trace
     * - stops xdebug trace, scans the trace file & return the called functions array
     * 
     * @param string $classTerm | default = '' | filter by class::function()
     * @param int $page | default = 1
     * @param int $limit | default = 10
     * @param bool $functionUnique | default = true | make array unique by function name
     * @param bool $sortByFunctionCount | default = false | sort by functionCount, MOST CALLED FUNCTIONS ON TOP
     * @param bool $saveCsvSummarize | default = false | summarize the trace result to sum up the function counts of same invoked functions
     * 
     * @return array|string|bool
     */
    public function getFilteredXDebugFunctionTrace(string $classTerm = '', int $page = 1, int $limit = 10, bool $functionUnique = true, bool $sortByFunctionCount = false, bool $saveCsvSummarize = false) {
        if ($this->checkEnvToEnable && !(boolean)getenv('ENABLE_XDEBUG_FUNCTION_TRACE')) {
            return false;
        }
        xdebug_stop_trace();
        $traceFileType = 'xt';
        $filePath = $this->getXdebugFunctionTraceFilePath(true, $traceFileType);
        $exceptionMessages = [
            "Trace file doesn't exists at given path : $filePath",
            "Trace file might doesn't have any content at given path : $filePath",
        ];

        if (!$filePath || !file_exists($filePath)) {
            return $exceptionMessages[0];
        }

        $lines = gzfile($filePath);
        if (!$lines) {
            return $exceptionMessages[1];
        }

        // remove empty lines
        $lines = array_values(array_filter(
            $lines, 
            fn($value) => !empty(trim(strip_tags($value)))
        ));

        // unset html header data
        unset($lines[0]);
        if (!$lines) {
            return $exceptionMessages[1];
        }

        // reset keys
        $lines = array_values($lines);
        
        // result keys in order as in actual xdebug trace function response
        $keyNames = ['#', 'time', 'mem', 'functionArrow', 'function', 'location'];

        // to exclude keys in result (e.g. not required keys)
        $excludeKeyNames = ['#', 'time', 'mem', 'functionArrow'];

        // set $keyNames & $excludeKeyNames according to xt file type scan
        if ($traceFileType == 'xt') {
            $keyNames = ['depth', 'timeEnter', 'memoryEnter', 'function', 'internal', 'location', 'line', 'params', 'timeExit', 'memoryExit', 'timeUsage', 'memoryDiff', 'return',];
            
            // to exclude keys in result (e.g. not required keys)
            $excludeKeyNames = ['depth', 'timeEnter', 'memoryEnter', 'internal', 'line', 'params', 'timeExit', 'memoryExit', 'memoryDiff', 'return',];
        }
        $filterColumnKey = ($classTerm) ? array_search('function', $keyNames) : false;
        
        $checkSlashSeparator = '\\';
        $rootPath = $this->getRootPath($checkSlashSeparator);
        
        // to store called functions data in array
        $calledFuncions = [];

        /**
         * @info : NESTED FUNCTION : check $string having $searchString
         * @param string $string
         * @param string $searchString
         * @return bool
         */
        function stringHasString(string $string, string $searchString) {
            return ($searchString && !strstr($string, $searchString)) ? false : true;
        }

        /**
         * @info : NESTED FUNCTION : remove $rootPath from $path
         * @param string $path
         * @param string $rootPath
         * @param string $checkSlashSeparator
         * @return string
         */
        function removeRootPathFromPath(string $path, string $rootPath, string $checkSlashSeparator) {
            $path = str_replace('/', $checkSlashSeparator, $path);
            return str_ireplace($rootPath, '', $path);
        }

        foreach ($lines as $key => $line) {
            if ($traceFileType == 'xt') {
                /**
                 * @info : get xt file line parts
                 * $parts array info :
                 * 0 : depth
                 * 1 : function number
                 * 2 : type (0 : function enter, 1 : function exit, R : function return)
                 * 3 : timeEnter/timeExit | i.e. (type == 0) && timeEnter; (type == 1) && timeExit;
                 * 4 : memoryEnter/memoryExit | i.e. (type == 0) && memoryEnter; (type == 1) && memoryExit;
                 * 5 : function name
                 * 6 : is internal
                 * 7 : params
                 * 8 : location
                 * 9 : line
                 */
                $parts = explode("\t", $line);

                // $parts should contain atleast 5 elements
                if (count($parts) < 5) {
                    continue;
                }
                $funcNum = (int) $parts[1];
                $type = $parts[2];

                switch ($type) {
                    case '0': // function enter
                        // filter by class::function()
                        $stringToBeFiltered = $parts[5];
                        $isIncludeOrRequire = ($parts[5] == 'include' || $parts[5] == 'require') ? true : false;
                        if ($isIncludeOrRequire) {
                            $tempParams = ($parts[7]) ? [$parts[7]] : array_slice($parts, 11);
                            $stringToBeFiltered = $tempParams[0];
                        }
                        if (!stringHasString($stringToBeFiltered, $classTerm)) {
                            continue;
                        }
                        // filter end

                        $calledFuncions[$funcNum] = [];
                        $calledFuncions[$funcNum]['depth'] = (int)$parts[0];
                        $calledFuncions[$funcNum]['timeEnter'] = $parts[3];
                        $calledFuncions[$funcNum]['memoryEnter'] = $parts[4];
                        $calledFuncions[$funcNum]['internal'] = !(bool)$parts[6];
                        $calledFuncions[$funcNum]['line'] = $parts[9];
                        $calledFuncions[$funcNum]['params'] = ($parts[7]) ? [$parts[7]] : array_slice($parts, 11);
                        // set function without rootPath & with ()
                        $calledFuncions[$funcNum]['function'] = removeRootPathFromPath(
                            sprintf('%s(%s)', trim($parts[5]), ($isIncludeOrRequire) ? $calledFuncions[$funcNum]['params'][0] : ''),
                            $rootPath,
                            $checkSlashSeparator
                        );
                        // set location without rootPath & with line number
                        $calledFuncions[$funcNum]['location'] = removeRootPathFromPath(
                            sprintf('%s:%s', trim($parts[8]), $calledFuncions[$funcNum]['line']),
                            $rootPath,
                            $checkSlashSeparator
                        );
                        // set below later
                        $calledFuncions[$funcNum]['timeExit'] = '';
                        $calledFuncions[$funcNum]['memoryExit'] = '';
                        $calledFuncions[$funcNum]['timeUsage'] = '';
                        $calledFuncions[$funcNum]['memoryDiff'] = '';
                        $calledFuncions[$funcNum]['return'] = '';
                        break;
                    case '1': // function exit
                        $calledFuncions[$funcNum]['timeExit'] = $parts[3];
                        $calledFuncions[$funcNum]['memoryExit'] = $parts[4];
                        $calledFuncions[$funcNum]['timeUsage'] = $calledFuncions[$funcNum]['timeExit'] - $calledFuncions[$funcNum]['timeEnter'];
                        $calledFuncions[$funcNum]['memoryDiff'] = (int)$calledFuncions[$funcNum]['memoryExit'] - (int)$calledFuncions[$funcNum]['memoryEnter'];
                        $calledFuncions[$funcNum]['timeUsage'] = sprintf('%f', $calledFuncions[$funcNum]['timeUsage']);
                        break;
                    case 'R'; // function return
                        $calledFuncions[$funcNum]['return'] = $parts[5];
                        break;
                }
                continue;
            }

            $dom = new \DOMDocument();
            $dom->loadHTML($line);
            $tds = $dom->getElementsByTagName('td');
            $tdData = [];
            
            // filter by class::function()
            if (!stringHasString($tds[$filterColumnKey]->textContent, $classTerm)) {
                continue;
            }
            
            foreach($tds as $tdKey => $tdNode) {
                // exclude not required keys in result
                if (in_array($keyNames[$tdKey], $excludeKeyNames)) {
                    continue;
                }
                $fieldText = trim($tdNode->textContent);
                // remove local path from location & function
                if ($keyNames[$tdKey] == 'location' || $keyNames[$tdKey] == 'function') {
                    $fieldText = removeRootPathFromPath($fieldText, $rootPath, $checkSlashSeparator);
                }
                $tdData[$keyNames[$tdKey]] = $fieldText;
            }
            
            if (!$tdData) {
                continue;
            }
            array_push($calledFuncions, $tdData);
        }

        // count number of function invokes
        $functionKeyArray = array_column($calledFuncions, 'function');
        $functionCounts = array_count_values($functionKeyArray);

        /**
         * - fill functionCount
         * - $functionUnique = true | make array unique by function name
         */
        $addedFunctions = [];
        $currentRoute = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
        foreach ($calledFuncions as $key => $value) {
            if ($traceFileType == 'xt') {
                // exclude not required keys in result
                foreach ($excludeKeyNames as $keyEK => $valueEK) {
                    if (isset($calledFuncions[$key][$valueEK])) {
                        unset($calledFuncions[$key][$valueEK]);
                    }
                }
            }

            // function should exists in value
            if (empty($value['function'])) {
                unset($calledFuncions[$key]);
                continue;
            }
            // make array unique by function name
            if ($functionUnique && in_array($value['function'], $addedFunctions)) {
                unset($calledFuncions[$key]);
                continue;
            }
            array_push($addedFunctions, $value['function']);
            $calledFuncions[$key]['functionCount'] = $functionCounts[$value['function']];
            $calledFuncions[$key]['currentRoute'] = $currentRoute;
        }

        // sort by functionCount, MOST CALLED FUNCTIONS ON TOP
        if ($sortByFunctionCount) {
            $calledFuncions = $this->sortMultidimensionalArrayByColumn($calledFuncions, 'functionCount');
        }

        // save $calledFuncions to CSV file
        $csvFilePath = $this->getXdebugFunctionTraceFilePath(false, 'csv');
        $this->saveMultidimensionalArrayToCSV($calledFuncions, $csvFilePath, true, true);

        // generate CSV summarize : summarize the trace result to sum up the function counts of same invoked functions
        if ($saveCsvSummarize) {
            $csvArray = $this->CSVToMultidimensionalArray($csvFilePath);
            $this->summarizeFilteredXDebugFunctionTrace($csvArray, 1, 10, $sortByFunctionCount);
        }

        // paginate $calledFuncions array 
        $page = abs($page);
        $limit = abs($limit);
        $start = ($page * $limit) - $limit;
        $calledFuncions = array_slice($calledFuncions, $start, $limit);
        return $calledFuncions;
    }

    /**
     * @info : summarize filtered xDebug function trace
     * - generate CSV summarize : summarize the trace result to sum up the function counts of same invoked functions
     * @param array $array
     * @param int $page | default = 1
     * @param int $limit | default = 10
     * @param bool $sortByFunctionCount | default = false | sort by functionCount, MOST CALLED FUNCTIONS ON TOP
     * @return array
     */
    public function summarizeFilteredXDebugFunctionTrace(array $array, int $page = 1, int $limit = 10, bool $sortByFunctionCount = false) {
        $filteredArray = [];
        $filteredArrayTemp = [];
        $addedFilterTerm = [];
        $summarizeArray = [];
        foreach ($array as $key => $value) {
            $filterColumn = 'function';
            $filterTerm = $value[$filterColumn];
            if (in_array($filterTerm, $addedFilterTerm)) {
                continue;
            }
            array_push($addedFilterTerm, $filterTerm);

            // filter same function name in array to sum up their count
            $filteredArray = array_filter($array, function($element) use($filterTerm, $filterColumn) {
                return isset($element[$filterColumn]) && $element[$filterColumn] == $filterTerm;
            });
            // sort by functionCount, MOST CALLED FUNCTIONS ON TOP
            $filteredArray = $this->sortMultidimensionalArrayByColumn($filteredArray, 'functionCount');

            $duplicationHandling = false;
            if ($duplicationHandling) {
                $filteredArrayTemp = $filteredArray;

                /**
                 * @info : remove functionCount column from $filteredArrayTemp
                 * - done this to filter same function, location & currentRoute calls further
                 * - functionCount can be different in same function except fields function, location & currentRoute
                 */
                $removeColumn = 'functionCount';
                array_walk($filteredArrayTemp, function (&$a) use($removeColumn) {
                    unset($a[$removeColumn]); 
                });

                // filter same function, location & currentRoute calls
                $filteredArrayTemp = array_map(
                    'unserialize', 
                    array_unique(
                        array_map(
                            'serialize', 
                            $filteredArrayTemp
                        )
                    )
                );

                $removeKeys = array_diff(array_keys($filteredArray), array_keys($filteredArrayTemp));
                $filteredArray = array_diff_key($filteredArray, array_flip($removeKeys));
            }

            $storeMe = [];
            $storeMe = array_values($filteredArray)[0];
            $storeMe['location'] = implode(',', array_unique(array_column($filteredArray, 'location')));
            $storeMe['functionCount'] = array_sum(array_column($filteredArray, 'functionCount'));
            // summarize execution time
            if (isset($storeMe['timeUsage'])) {
                $storeMe['timeUsage'] = sprintf('%f', array_sum(array_column($filteredArray, 'timeUsage')));
            }
            unset($storeMe['currentRoute']);
            array_push($summarizeArray, $storeMe);
        }

        $summarizeCsvFilePath = $this->getXdebugFunctionTraceFilePath(false, 'csv', 'xdebug-function-trace-summarize');
        // sort by functionCount, MOST CALLED FUNCTIONS ON TOP
        if ($sortByFunctionCount) {
            $summarizeArray = $this->sortMultidimensionalArrayByColumn($summarizeArray, 'functionCount');
        }
        $this->saveMultidimensionalArrayToCSV($summarizeArray, $summarizeCsvFilePath);

        // paginate $summarizeArray array 
        $page = abs($page);
        $limit = abs($limit);
        $start = ($page * $limit) - $limit;
        $summarizeArray = array_slice($summarizeArray, $start, $limit);
        return $summarizeArray;
    }

    /**
     * @info : get xdebug function trace file path
     * @param bool $appendDotGZ | default = false
     * @param string $fileType | default = 'html'
     * @param string $traceFileName | default = 'xdebug-function-trace'
     * @return string
     */
    private function getXdebugFunctionTraceFilePath(bool $appendDotGZ = false, string $fileType = 'xt', string $traceFileName = 'xdebug-function-trace') {
        $allowedFileTypes = ['xt', 'html', 'csv'];
        $fileType = (in_array($fileType, $allowedFileTypes)) ? $fileType : $allowedFileTypes[0];
        $traceFileName = trim(strip_tags($traceFileName));
        $traceFileName = (!$traceFileName) ? 'xdebug-function-trace' : $traceFileName;
        $traceFilePath = sprintf('%s/%s.%s', $this->traceFilePath, $traceFileName, $fileType);
        return sprintf('%s%s', $traceFilePath, ($appendDotGZ) ? '.gz' : '');
    }

    /**
     * @info : save multidimensional array to CSV
     * 
     * @param array $array
     * @param string $csvFilePath
     * @param bool $append | default = false | false : replace old logs with new, true : append logs with old logs
     * @param bool $attachEmptyRowOnAppend | default = false | to identify logs from this point are for another page load
     */
    private function saveMultidimensionalArrayToCSV(array $array, string $csvFilePath, bool $append = false, bool $attachEmptyRowOnAppend = false) {
        $csvOpenMode = ($append && !empty(trim(file_get_contents($csvFilePath)))) ? 'a' : 'w';
        if ($fileObject = fopen($csvFilePath, $csvOpenMode)) {
            // loop through file pointer and a line
            $keyPos = 0;
            foreach ($array as $key => $fields) {
                // header row
                if ($csvOpenMode == 'w' && $keyPos == 0) {
                    fputcsv($fileObject, array_keys($array[$key]));
                }
                // empty row, to identify logs from this point are for another page load
                if ($csvOpenMode == 'a' && $keyPos == 0 && $attachEmptyRowOnAppend) {
                    fputcsv($fileObject, []);
                }
                // logs
                fputcsv($fileObject, $fields);
                $keyPos++;
            }
            fclose($fileObject);
        }
    }

    /**
     * @info : get multidimensional array as per the given $csvFilePath
     * @param string $csvFilePath
     * @param bool $ignoreEmptyRows | default = true
     * @return array
     */
    private function CSVToMultidimensionalArray(string $csvFilePath, bool $ignoreEmptyRows = true) {
        $csvArray = [];
        if ($fileObject = fopen($csvFilePath, 'r')) {
            // CSV first line is header
            $csvHeaderArray = fgetcsv($fileObject);
            while ($csvLine = fgetcsv($fileObject)) {
                if ($ignoreEmptyRows && !trim(implode('', $csvLine))) {
                    continue;
                }
                // generate header column => value multidimentional array as stored in CSV then store it to $csvArray
                $csvLine = array_combine(array_map(function($element) use ($csvHeaderArray) {
                    return $csvHeaderArray[$element];
                }, array_keys($csvLine)), array_values($csvLine));
                array_push($csvArray, $csvLine);
            }
        }
        return $csvArray;
    }

    /**
     * @info : sort multidimensional array by column
     * @param array $array
     * @param string $column
     * @param int $sortDirection | default = SORT_DESC (3)
     * @return array
     */
    private function sortMultidimensionalArrayByColumn(array $array, string $column, int $sortDirection = SORT_DESC) {
        $allowedSortDirections = [SORT_DESC, SORT_ASC];
        $sortDirection = (in_array($sortDirection, $allowedSortDirections)) ? $sortDirection : $allowedSortDirections[0];
        array_multisort(
            array_column($array, $column),
            $sortDirection,
            $array
        );
        return $array;
    }

    /**
     * @info : get project root path
     */
    private function getRootPath($setSlashSeparator = '\\') {
        return str_replace('/', $setSlashSeparator, realpath($this->rootPath));
    }

    /**
     * @info : get project root directory
     */
    private function getRootDirectory($checkSlashSeparator = '\\') {
        $rootPath = $this->getRootPath($checkSlashSeparator);
        return end(explode($checkSlashSeparator, rtrim($rootPath, $checkSlashSeparator)));
    }
}