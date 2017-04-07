<?php
/**
 * Piwik - free/libre analytics platform
 *
 * @link http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 *
 */
namespace Piwik\Plugins\SearchMonitor;

use Piwik\Common;
use Piwik\Config;
use Piwik\DataTable;
use Piwik\DataTable\Row;
use Piwik\Piwik;
use Piwik\Site;


/**
 * API for plugin SearchMonitor
 *
 * @method static \Piwik\Plugins\SearchMonitor\API getInstance()
 */
class API extends \Piwik\Plugin\API
{
    private function getModel()
    {
        return new Model();
    }

    public function getDateArrayForEvolution($period, $date)
    {
        if ($date == 'yesterday') {
            $date = date('Y-m-d', strtotime("-1 days"));
        } elseif ($date == 'today') {
            $date = date('Y-m-d');
        }

        $dateArray = array();
        $timeIncrease = '';

        if (strpos($date, ',') !== false) {
            $spiltDate = explode(',', $date);
            $startDate = date('Y-m-d', strtotime($spiltDate[0]));
            $endDate = date('Y-m-d', strtotime($spiltDate[1]));

            if ($period == 'week') {
                $startDate = date('Y-m-d', strtotime($endDate . ' - 70 days'));
            } elseif ($period == 'month') {
                $startDate = date('Y-m-01', strtotime($endDate . ' - 180 days'));
            }

            if ($period == 'day' || $period == 'range') {
                $timeIncrease = ' + 1 days';
            } elseif ($period == 'week') {
                $timeIncrease = ' + 7 days';
            } elseif ($period == 'month') {
                $timeIncrease = ' + 1 month';
            }

            $mons = array(1 => "Jan", 2 => "Feb", 3 => "Mar", 4 => "Apr", 5 => "May", 6 => "Jun", 7 => "Jul", 8 => "Aug", 9 => "Sep", 10 => "Oct", 11 => "Nov", 12 => "Dec");

            for ($i = $startDate; $i <= $endDate; $i = date('Y-m-d', strtotime($i . $timeIncrease))) {
                if ($period == 'day') {
                    $dateArray[$i] = $i;
                } elseif ($period == 'week') {
                    $start = date('Y/m/d', strtotime($i));
                    $end = date('Y/m/d', strtotime($i . ' + 6 days'));
                    $label = $start . ' - ' . $end;
                    $dateArray[$i] = $label;
                } elseif ($period == 'month') {
                    $d = date_parse_from_format('Y-m-d', $i);
                    $label = $mons[$d['month']] . ' ' . $d['year'];
                    $dateArray[$i] = $label;
                }
            }
            return $dateArray;
        }

        return $dateArray;
    }

    private function loadLastVisitorDetailsFromDatabase($idSite, $period, $date, $segment = false, $offset = 0, $limit = 100, $minTimestamp = false, $filterSortOrder = false, $visitorId = false)
    {
        $model = new Model();
        $data = $model->queryLogVisits($idSite, $period, $date, $segment, $offset, $limit, $visitorId, $minTimestamp, $filterSortOrder);
        return $this->makeVisitorTableFromArray($data);
    }

    private function makeVisitorTableFromArray($data)
    {
        $dataTable = new DataTable();
        $dataTable->addRowsFromSimpleArray($data);

        if (!empty($data[0])) {
            $columnsToNotAggregate = array_map(function () {
                return 'skip';
            }, $data[0]);

            $dataTable->setMetadata(DataTable::COLUMN_AGGREGATION_OPS_METADATA_NAME, $columnsToNotAggregate);
        }

        return $dataTable;
    }

    private function addFilterToCleanVisitors(DataTable $dataTable, $idSite, $flat = false, $doNotFetchActions = false, $filterNow = true)
    {
        $filter = 'queueFilter';
        if ($filterNow) {
            $filter = 'filter';
        }

        $dataTable->$filter(function ($table) use ($idSite, $flat, $doNotFetchActions) {

            /** @var DataTable $table */
            $actionsLimit = (int)Config::getInstance()->General['visitor_log_maximum_actions_per_visit'];

            $visitorFactory = new VisitorFactory();
            $website = new Site($idSite);
            $timezone = $website->getTimezone();

            // live api is not summable, prevents errors like "Unexpected ECommerce status value"
            $table->deleteRow(DataTable::ID_SUMMARY_ROW);

            foreach ($table->getRows() as $visitorDetailRow) {
                $visitorDetailsArray = Visitor::cleanVisitorDetails($visitorDetailRow->getColumns());

                $visitor = $visitorFactory->create($visitorDetailsArray);
                $visitorDetailsArray = $visitor->getAllVisitorDetails();

                $visitorDetailsArray['actionDetails'] = array();
                if (!$doNotFetchActions) {
                    $visitorDetailsArray = Visitor::enrichVisitorArrayWithActions($visitorDetailsArray, $actionsLimit, $timezone);
                }

                if ($flat) {
                    $visitorDetailsArray = Visitor::flattenVisitorDetailsArray($visitorDetailsArray);
                }

                $visitorDetailRow->setColumns($visitorDetailsArray);
            }

        });
    }

    public function getLastVisitsDetails($idSite, $period = false, $date = false, $segment = false, $filterLimit = 100, $filterOffset = 0, $countVisitorsToFetch = false, $minTimestamp = false, $flat = false, $doNotFetchActions = false)
    {
        Piwik::checkUserHasViewAccess($idSite);

        $filterSortOrder = Common::getRequestVar('filter_sort_order', false, 'string');

        $dataTable = $this->loadLastVisitorDetailsFromDatabase($idSite, $period, $date, $segment, $filterOffset, $filterLimit, $minTimestamp, $filterSortOrder, $visitorId = false);

        $this->addFilterToCleanVisitors($dataTable, $idSite, $flat, false);

        $filterSortColumn = Common::getRequestVar('filter_sort_column', false, 'string');

        if ($filterSortColumn) {
            $this->logger->warning('Sorting the API method "Live.getLastVisitDetails" by column is currently not supported. To avoid this warning remove the URL parameter "filter_sort_column" from your API request.');
        }

        // Usually one would Sort a DataTable and then apply a Limit. In this case we apply a Limit first in SQL
        // for fast offset usage see https://github.com/piwik/piwik/issues/7458. Sorting afterwards would lead to a
        // wrong sorting result as it would only sort the limited results. Therefore we do not support a Sort for this
        // API

        $dataTable->disableFilter('Sort');

        $dataTable->disableFilter('Limit'); // limit is already applied here

        return $dataTable;
    }

    /**
     * @param $idSite
     * @param $day
     * @return mixed
     */
    public function getVisitDetailsFromApiByPage($idSite, $period, $date, $segment = false, $filter_offset = 0)
    {
        $filter_limit = 100;
        return $this->getLastVisitsDetails($idSite, $period, $date, $segment, $filter_limit, $filter_offset, false);
    }

    public function getVisitDetailsFromApiByPageCount($idSite, $period, $date, $segment = false, $filter_offset = 0, $filter_limit = 100)
    {
        $metatable = new DataTable();
        $dateArray = $this->getDateArrayForEvolution($period, $date);
        foreach ($dateArray as $day => $label) {
            $data = $this->getLastVisitsDetails($idSite, $period, $day, $segment, $filter_limit, $filter_offset, false);
            $metatable->addRowFromArray(array(Row::COLUMNS => array(
                'label' => $label,
                'count' => $data->getRowsCount()
            )));

            while ($data->getRowsCount() >= $filter_limit) {
                $filter_offset = $filter_offset + $filter_limit;
                $data = $this->getLastVisitsDetails($idSite, $period, $day, $segment, $filter_limit, $filter_offset, false);
                $metatable->addRowFromArray(array(Row::COLUMNS => array(
                    'label' => $label,
                    'count' => $data->getRowsCount()
                )));
            }
        }
        return $metatable;
    }

    /**
     * Another example method that returns a data table.
     * @param int $idSite
     * @param string $period
     * @param string $date
     * @return DataTable
     * @internal param bool|string $segment
     */
    public function getPaceTimeOnSearchResultTendency($idSite, $period, $date, $segment = false)
    {
        $dateArray = $this->getDateArrayForEvolution($period, $date);
        $metatable = new DataTable();

        foreach ($dateArray as $day => $label) {
            $sumPaceTime = 0;
            $sumVisits = 0;
            if (strpos($date, ',') !== false) {
                $filter_offset = 0;
                $data = $this->getVisitDetailsFromApiByPage($idSite, $period, $day, $segment, $filter_offset);
                list($sumVisits, $sumPaceTime) = $this->getAvgTimeOnPage($data, $sumVisits, $sumPaceTime);
                while ($data->getRowsCount() >= 100) {
                    $filter_offset = $filter_offset + 100;
                    $data = $this->getVisitDetailsFromApiByPage($idSite, $period, $day, $segment, $filter_offset);
                    list($sumVisits, $sumPaceTime) = $this->getAvgTimeOnPage($data, $sumVisits, $sumPaceTime);
                }
            }
            $avgTimeOnPage = 0;
            if ($sumVisits > 0) {
                $avgTimeOnPage = $sumPaceTime / $sumVisits;
            }

            $metatable->addRowFromArray(array(Row::COLUMNS => array(
                'label' => $label, 'avg_time_on_page' => $avgTimeOnPage)));
        }

        return $metatable;
    }

    public function getDataOfPaceTimeOnSearchResultDistribution($idSite, $period, $date, $segment = false)
    {
        $metatable = new DataTable();
        $filter_offset = 0;
        $data = $this->getVisitDetailsFromApiByPage($idSite, $period, $date, $segment, $filter_offset);
        $this->getAvgTimeOnPageDistribution($data, $metatable);
        while ($data->getRowsCount() >= 100) {
            $filter_offset = $filter_offset + 100;
            $data = $this->getVisitDetailsFromApiByPage($idSite, $period, $date, $segment, $filter_offset);
            $this->getAvgTimeOnPageDistribution($data, $metatable);
        }
        return $metatable;
    }

    /**
     * Another example method that returns a data table.
     * @param int $idSite
     * @param string $period
     * @param string $date
     * @return DataTable
     * @throws \Exception
     * @internal param bool|string $segment
     */
    public function getPaceTimeOnSearchResultDistribution($idSite, $period, $date, $segment = false)
    {
        $table = new DataTable();
        $table->addRowsFromSimpleArray(array(
            array('label' => '0-5s', 'Count' => 0),
            array('label' => '5-10s', 'Count' => 0),
            array('label' => '10-30s', 'Count' => 0),
            array('label' => '30-60s', 'Count' => 0),
            array('label' => '60s above', 'Count' => 0)
        ));

        $metatable = $this->getDataOfPaceTimeOnSearchResultDistribution($idSite, $period, $date, $segment);

        foreach ($metatable->getRows() as $row) {
            $value = $row->getColumn('avg_time_on_page');
            $resultRow = null;

            if (0 <= $value && $value < 5) {
                $resultRow = $table->getRowFromLabel('0-5s');
            } elseif (5 <= $value && $value < 10) {
                $resultRow = $table->getRowFromLabel('5-10s');
            } elseif (10 <= $value && $value < 30) {
                $resultRow = $table->getRowFromLabel('10-30s');
            } elseif (30 <= $value && $value < 60) {
                $resultRow = $table->getRowFromLabel('30-60s');
            } elseif (60 <= $value) {
                $resultRow = $table->getRowFromLabel('60s above');
            }

            if ($resultRow != null) {
                $counter = $resultRow->getColumn('Count');
                $resultRow->setColumn('Count', $counter + 1);
            }
        }


        return $table;
    }

    /**
     * Another example method that returns a data table.
     * @param int $idSite
     * @param string $period
     * @param string $date
     * @return DataTable
     * @internal param bool|string $segment
     */
    public function getRepeatingSearchCount($idSite, $period, $date, $segment = false)
    {
        $dateArray = $this->getDateArrayForEvolution($period, $date);
        $metatable = new DataTable();

        foreach ($dateArray as $day => $label) {
            list($repeatingSearchCount, $totalSearchCount) = $this->getRepeatingSearchInfo($idSite, $period, $date, $segment, $day);

            $metatable->addRowFromArray(array(Row::COLUMNS => array(
                'label' => $label,
                'repeating_search_count' => $repeatingSearchCount,
                'total_search_count' => $totalSearchCount,
            )));
        }

        return $metatable;
    }

    /**
     * @param $idSite
     * @param $period
     * @param $date
     * @param $segment
     * @param $day
     * @return array
     */
    public function getRepeatingSearchInfo($idSite, $period, $date, $segment = false, $day)
    {
        $repeatSearchRecords = array();
        if (strpos($date, ',') !== false) {
            $filter_offset = 0;
            $data = $this->getVisitDetailsFromApiByPage($idSite, $period, $day, $segment, $filter_offset);
            $repeatSearchRecords = $this->getRepeatSearchData($data, $repeatSearchRecords);
            while ($data->getRowsCount() >= 100) {
                $filter_offset = $filter_offset + 100;
                $data = $this->getVisitDetailsFromApiByPage($idSite, $period, $day, $segment, $filter_offset);
                $repeatSearchRecords = $this->getRepeatSearchData($data, $repeatSearchRecords);
            }
        }

        if (array_key_exists(1, $repeatSearchRecords)) {
            $successSearchCount = $repeatSearchRecords[1];
        } else {
            $successSearchCount = 0;
        }
        $repeatingSearchCount = 0;

        foreach ($repeatSearchRecords as $key => $value) {
            if ($key > 1) { //only if repeat search larger than 1, see it as a repeating search
                $repeatingSearchCount += $repeatSearchRecords[$key];
            }
        }

        $totalSearchCount = $successSearchCount + $repeatingSearchCount;
        return array($repeatingSearchCount, $totalSearchCount);
    }

    /**
     * @param $repeatSearchTimes
     * @param $repeatSearchRecords
     * @return mixed
     */
    public function addRepeatSearchTimes($repeatSearchTimes, $repeatSearchRecords)
    {
        if (array_key_exists($repeatSearchTimes, $repeatSearchRecords) && $repeatSearchRecords[$repeatSearchTimes] > 0) {
            $repeatSearchRecords[$repeatSearchTimes]++;
        } else {
            $repeatSearchRecords[$repeatSearchTimes] = 1;
        }
        return $repeatSearchRecords; //value represent the search count for this kind of repeat search
    }

    public function getRepeatingSearchRate($idSite, $period, $date, $segment = false)
    {
        $dateArray = $this->getDateArrayForEvolution($period, $date);
        $metatable = new DataTable();

        foreach ($dateArray as $day => $label) {
            list($repeatingSearchCount, $totalSearchCount) = $this->getRepeatingSearchInfo($idSite, $period, $date, $segment, $day);
            if ($totalSearchCount == 0) {
                $repeatingRate = 0;
            } else {
                $repeatingRate = ($repeatingSearchCount * 100) / ($totalSearchCount * 1.0);
            }

            $metatable->addRowFromArray(array(Row::COLUMNS => array(
                'label' => $label,
                'repeating_rate' => $repeatingRate
            )));
        }

        return $metatable;
    }


    /**
     * @param $data
     * @param $totalSearchCount
     * @param $bouncedSearchCount
     * @return array
     */
    private function getBounceSearchData($data, $totalSearchCount, $bouncedSearchCount)
    {
        foreach ($data as $row) {
            $detail = $row->getColumn('actionDetails');
            for ($index = 0; $index < count($detail); ++$index) {
                if ($detail[$index]['type'] == 'search') {
                    $searchWord = $detail[$index]['siteSearchKeyword'];
                    $totalSearchCount++;
                    $searchSuccess = false;
                    if ($index == count($detail) - 1) {
                        $bouncedSearchCount++;
                    } else {
                        if ($detail[$index - 1]['type'] === 'event' &&
                            $detail[$index - 1]['eventCategory'] === 'searchResult' &&
                            $detail[$index - 1]['eventAction'] === $searchWord
                        ) {
                            $searchSuccess = true;
                        }

                        $checkSearchSuccess = $index + 1;
                        while ($checkSearchSuccess < count($detail)) {
                            if ($detail[$checkSearchSuccess]['type'] === 'event' &&
                                $detail[$checkSearchSuccess]['eventCategory'] === 'searchResult' &&
                                $detail[$checkSearchSuccess]['eventAction'] === $searchWord
                            ) {
                                $searchSuccess = true;
                                break;
                            }
                            if ($detail[$checkSearchSuccess]['type'] === 'search') {
                                $searchSuccess = false;
                                break;
                            }
                            $checkSearchSuccess++;
                        }

                        if (!$searchSuccess) {
                            $bouncedSearchCount++;
                        }
                    }
                }
            }
        }

        return array($totalSearchCount, $bouncedSearchCount);
    }

    /**
     * @param $idSite
     * @param $period
     * @param $date
     * @param $segment
     * @param $day
     * @return array
     */
    public function getBounceSearchInfo($idSite, $period, $date, $segment = false, $day)
    {
        $bouncedSearchCount = 0;
        $totalSearchCount = 0;
        if (strpos($date, ',') !== false) {
            $filter_offset = 0;
            $data = $this->getVisitDetailsFromApiByPage($idSite, $period, $day, $segment, $filter_offset);
            list($totalSearchCount, $bouncedSearchCount) = $this->getBounceSearchData($data, $totalSearchCount, $bouncedSearchCount);

            while ($data->getRowsCount() >= 100) {
                $filter_offset = $filter_offset + 100;
                $data = $this->getVisitDetailsFromApiByPage($idSite, $period, $day, $segment, $filter_offset);
                list($totalSearchCount, $bouncedSearchCount) = $this->getBounceSearchData($data, $totalSearchCount, $bouncedSearchCount);
            }
        }

        $this->getModel()->addBounceDataToDB($day, $bouncedSearchCount, $totalSearchCount);

        return array($bouncedSearchCount, $totalSearchCount);
    }

    public function getBounceSearchRate($idSite, $period, $date, $segment = false)
    {
        $dateArray = $this->getDateArrayForEvolution($period, $date);
        $metatable = new DataTable();

        foreach ($dateArray as $day => $label) {
            list($bouncedSearchCount, $totalSearchCount) = $this->getBounceSearchInfo($idSite, $period, $date, $segment, $day);
            if ($totalSearchCount == 0) {
                $bounceRate = 0;
            } else {
                $bounceRate = ($bouncedSearchCount * 100) / ($totalSearchCount * 1.0);
            }
            $metatable->addRowFromArray(array(Row::COLUMNS => array(
                'label' => $label,
                'bounce_search_rate' => $bounceRate
            )));
        }

        return $metatable;
    }

    public function getBounceSearchCount($idSite, $period, $date, $segment = false)
    {
        $dateArray = $this->getDateArrayForEvolution($period, $date);
        $metatable = new DataTable();
        foreach ($dateArray as $day => $label) {
            list($bouncedSearchCount, $totalSearchCount) = $this->getBounceSearchInfo($idSite, $period, $date, $segment, $day);
            $metatable->addRowFromArray(array(Row::COLUMNS => array(
                'label' => $label,
                'bounce_search_count' => $bouncedSearchCount,
                'total_search_count' => $totalSearchCount
            )));
        }

        return $metatable;
    }

    public function getKeywordRelatedInfo($idSite, $period, $date, $segment = false, $reqKeyword = null)
    {
        $table = new DataTable();
        $filter_offset = 0;
        $data = $this->getVisitDetailsFromApiByPage($idSite, $period, $date, $segment, $filter_offset);
        $this->getRelatedData($reqKeyword, $data, $table);
        while ($data->getRowsCount() >= 100) {
            $filter_offset = $filter_offset + 100;
            $data = $this->getVisitDetailsFromApiByPage($idSite, $period, $date, $segment, $filter_offset);
            $this->getRelatedData($reqKeyword, $data, $table);
        }
        return $table;
    }


    /**
     * Another example method that returns a data table.
     * @param int $idSite
     * @param string $period
     * @param string $date
     * @param bool|string $segment
     * @return DataTable
     */
    public function getSearchKeywords($idSite, $period, $date, $segment = false)
    {
        return \Piwik\API\Request::processRequest('Actions.getSiteSearchKeywords', array(
            'idSite' => $idSite,
            'period' => $period,
            'date' => $date,
            'segment' => $segment,
            'filter_limit' => -1
        ));
    }

    /**
     * @param $data
     * @param $metatable
     */
    private function getAvgTimeOnPageDistribution($data, $metatable)
    {
        foreach ($data as $row) {
            $detail = $row->getColumn('actionDetails');
            $isResult = array();
            foreach ($detail as $action) {
                if ($action['type'] == 'event' && $action['eventCategory'] == 'searchResult') {
                    $isResult[] = $action['eventName'];
                }
                $key = array_search($action['url'], $isResult);
                if ($action['type'] == 'action' && $key !== FALSE) {
                    $visitTime = $action['timeSpent'];
                    $metatable->addRowFromArray(array(Row::COLUMNS => array('avg_time_on_page' => $visitTime, 'serverTimePretty' => $action['serverTimePretty'])));
                    unset($isResult[$key]);
                }
            }
        }
    }

    /**
     * @param $data
     * @param $repeatSearchRecords
     * @return mixed
     */
    private function getRepeatSearchData($data, $repeatSearchRecords)
    {
        foreach ($data as $row) {
            $detail = $row->getColumn('actionDetails');
            $isFirstSearch = true;
            $repeatSearchTimes = 0;
            $previousSearchTimeStamp = -1;
            for ($index = 0; $index < count($detail); ++$index) {
                if ($detail[$index]['type'] == 'search') {
                    if ($isFirstSearch) {
                        $repeatSearchTimes = 1;
                        $previousSearchTimeStamp = $detail[$index]['timestamp'];
                        $isFirstSearch = false;
                    } else {
                        $timeInterval = $detail[$index]['timestamp'] - $previousSearchTimeStamp;

                        if ($timeInterval <= 180 && ($detail[$index]['timestamp'] - $previousSearchTimeStamp) >= 0) {
                            $repeatSearchTimes++; //within specific time range, another repeat search
                        } else {
                            $repeatSearchRecords = $this->addRepeatSearchTimes($repeatSearchTimes, $repeatSearchRecords);
                            $repeatSearchTimes = 1;  //reset the repeatSearchTime. Because we'll start another round calculate
                        }
                        $previousSearchTimeStamp = $detail[$index]['timestamp'];
                    }
                }
                if ($index == (count($detail) - 1)) { //the last item in this action detail
                    $repeatSearchRecords = $this->addRepeatSearchTimes($repeatSearchTimes, $repeatSearchRecords);
                }
            }
        }
        return $repeatSearchRecords;
    }

    /**
     * @param $data
     * @param $sumVisits
     * @param $sumPaceTime
     * @return array
     */
    private function getAvgTimeOnPage($data, $sumVisits, $sumPaceTime)
    {
        foreach ($data as $row) {

            $detail = $row->getColumn('actionDetails');
            $isResult = array();
            foreach ($detail as $action) {
                if ($action['type'] == 'event' && $action['eventCategory'] == 'searchResult') {
                    $isResult[] = $action['eventName'];
                }
                $key = array_search($action['url'], $isResult);
                if ($action['type'] == 'action' && $key !== FALSE) {
                    $visitTime = $action['timeSpent'];
                    $sumVisits++;
                    $sumPaceTime += $visitTime;
                    unset($isResult[$key]);
                }
            }
        }
        return array($sumVisits, $sumPaceTime);
    }

    /**
     * @param $reqKeyword
     * @param $data
     * @param $table
     */
    private function getRelatedData($reqKeyword, $data, $table)
    {
        foreach ($data as $row) {
            $detail = $row->getColumn('actionDetails');
            foreach ($detail as $action) {

                if ($action['type'] == 'event' && $action['eventCategory'] == 'searchResult'
                    && $action['eventName'] != "null"
                ) {

                    $keyword = $action['eventAction'];
                    if ($reqKeyword != null && $reqKeyword == $keyword) {
                        $srcURL = $action['eventName'];
                        $type = 'content';
                        if (preg_match("/^[^\/]+\/\/[^\/]+\/groups\/[^\/]+$/", $srcURL)) {
                            $type = 'group';
                        } else if (preg_match("/^[^\/]+\/\/[^\/]+\/people\/[^\/]+$/", $srcURL)) {
                            $type = 'people';
                        }
                        $table->addRowFromArray(array(Row::COLUMNS => array(
                            'url' => $srcURL, 'type' => $type)));
                    }
                }
            }
        }
    }

}
