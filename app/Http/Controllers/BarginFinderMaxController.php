<?php

namespace App\Http\Controllers;

use App\Models\Airport;
use App\Models\Flight;
use DateTime;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Http\Request;
use App\Traits\ApiResponseTrait;
use App\Traits\BarginFinderMaxTrait;
use App\Traits\CallApiTrait;
use App\Traits\FlightResultsTrait;
use Config;
use App\Models\TagId;
use App\StripeInit;
use Carbon\Carbon;

class BarginFinderMaxController extends Controller
{
    use ApiResponseTrait;
    use CallApiTrait;
    use FlightResultsTrait;
    use BarginFinderMaxTrait;

    private $customAirlinePercentage = 5;

    // add un-accepted airlines to exclude it from search results
    public $unacceptable_airlines = [
        ['Code' => 'WJ', 'Type' => "Operating"],
        ['Code' => '4N', 'Type' => "Operating"],
        ['Code' => 'BH', 'Type' => "Operating"],
        ['Code' => 'MO', 'Type' => "Operating"],
        ['Code' => 'F9', 'Type' => "Operating"],
        ['Code' => 'JQ', 'Type' => "Operating"],
        ['Code' => '3K', 'Type' => "Operating"],
        ['Code' => 'GK', 'Type' => "Operating"],
        ['Code' => 'BL', 'Type' => "Operating"],
        ['Code' => 'NK', 'Type' => "Operating"],
        ['Code' => 'WG', 'Type' => "Operating"],
        ['Code' => 'VB', 'Type' => "Operating"],
        ['Code' => 'BA', 'Type' => "Operating"]
    ];
    // first page pagination range # 10
    private $firstPage = 10;
    /*  we assume that all data will be validated by the front end
        we have three types:
        1 - Return.   2 - OneWay.  3 - OpenJaw.
     */
    public function index(Request $request)
    {
        // create url (static url till now)
        $url = $this->prepareUrl();
        // read trip type
        $tripType = $request->trip_type;
        // create body

        //check if the departure Date is not less than tomorrow
        if($this->checkDepartureDate($request->departure_date)){
            return $this->apiResponse(null, 'Not Found', 404);
        }
        $searchRequestBody = $this->createSearchRequestBody($request);

        // call the api
        $result = app()->makeWith(CallApiTrait::class, ['url' => $url, 'body' => $searchRequestBody]);


        if (!$result instanceof \Throwable)  // check if result is not containing any exception or error
        {


            /*delete any expiry request flight (after 20m)*/
            Flight::where('expiration_date', '<', now())->delete();

            $output = $this->formatResults($result, $tripType);

            /* store any flight request in flights table*/
            $expiry_date = date('Y-m-d H:i:s', time() + (20 * 60));
            $flight = new Flight();
            $flight->RequestID = $output['RequestID'];
            $flight->expiration_date = $expiry_date;
            $flight->Itineraries = $output['Itineraries'];
            $flight->total_item = $output['pages']['total_item'];
            $flight->save();

            // return first 10 values
            $output['Itineraries'] = array_slice($output['Itineraries'], 0, $this->firstPage);
            /*return data with api response*/
            return $this->apiResponse($output, null, 200);
        }
        return $this->apiResponse(null, $result->getMessage(), $result->getCode());
    }

    // a function to handle filter process
    public function filter(Request $request)
    {
        Flight::where('expiration_date', '<', now())->delete();

        $flights = Flight::where('RequestID', $request->requestID)->first();
        if ($flights) {
            $itineraries = $flights['Itineraries'];

            foreach ($itineraries as $itinerary_key => $itinerary) {
                $i = 0;
                foreach ($itinerary['flights'] as $flight_key => $flight) {
                    if ($request->stops != null) {
                        if ((($request->stops) + 1) != count($flight)) {
                            unset($itineraries[$itinerary_key]);
                            break;
                        }
                    }
                    foreach ($flight as $stop_key => $stop) {
                        if ($request->airlines) {
                            if ($stop['OperatingAirline'] == $request->airlines) {
                                $i++;
                            }
                        }
                    }
                }
                if ($request->airlines) {
                    if ($i == 0) {
                        unset($itineraries[$itinerary_key]);
                    }
                }
            }

            $length = count($itineraries) < 10 ? count($itineraries) : 10;
            $offset = $request->pageNo ? $this->calcOffset($request->pageNo) : 0;

            $data['Itineraries'] = array_slice($itineraries, $offset, $length);
            $data['RequestID'] = $request->requestID;
            $data['pages']['current_page_size'] = count($data['Itineraries']);
            $data['pages']['total_item'] = count($itineraries);
            $data['pages']['offset'] = $offset + 1;

            return $this->apiResponse($data, null, 200);
        } else {
            return $this->apiResponse(null, 'Not Found', 404);
        }
    }

    // a function to handle pagination process
    public function paginate(Request $request)
    {
        Flight::where('expiration_date', '<', now())->delete();

        $flights = Flight::where('RequestID', $request->requestID)->first();

        if ($flights) {

            if (!$request->pageNo) {
                $request->pageNo = 1;
            }

            $length = 10;
            $offset = $this->calcOffset($request->pageNo);

            $data['Itineraries'] = array_slice($flights['Itineraries'], $offset, $length);
            $data['RequestID'] = $request->requestID;
            $data['pages']['current_page_size'] = count($data['Itineraries']);
            $data['pages']['total_item'] = $flights->total_item;
            $data['pages']['offset'] = $offset + 1;

            return $this->apiResponse($data, null, 200);
        } else {
            return $this->apiResponse(null, 'Not Found', 404);
        }
    }

    // format results of the search
    private function formatResults($result, $tripType)
    {
        $flightData = []; // an array that contain a one flight data
        // get the itineraries data
        $itineraries = $result['OTA_AirLowFareSearchRS']['PricedItineraries']['PricedItinerary'];

        foreach ($itineraries as $key => $itinerary) {
            // flight data
            $tripFlightDataArray = $itinerary['AirItinerary']['OriginDestinationOptions']['OriginDestinationOption'];
            $flightData['Itineraries'][$key]['flights'] = ($tripType == 'OneWay') ? $this->createFlightSegmentsOneWayTrip($tripFlightDataArray) : $this->createFlightSegmentsMulti($tripFlightDataArray);
            // flight price data
            $tripPricesArray = $itinerary['AirItineraryPricingInfo']; //
            $flightData['Itineraries'][$key]['prices'] = $this->createFlightPrices($tripPricesArray[0], $flightData['Itineraries'][$key]['flights'], $tripType);

            $flightData['Itineraries'][$key]['taxfees']['Amount'] = $this->totalTax($itinerary['AirItineraryPricingInfo'][0]['PTC_FareBreakdowns']['PTC_FareBreakdown']);
            $flightData['Itineraries'][$key]['taxfees']['CurrencyCode'] = $itinerary['AirItineraryPricingInfo'][0]['ItinTotalFare']['Taxes']['Tax'][0]['CurrencyCode'];
            // basefare = total price - total taxes
            $flightData['Itineraries'][$key]['BaseFare']['Amount'] =  round($flightData['Itineraries'][$key]['prices']['ItinTotalFare'] - $flightData['Itineraries'][$key]['taxfees']['Amount'],2) ;
            $flightData['Itineraries'][$key]['BaseFare']['CurrencyCode'] = $itinerary['AirItineraryPricingInfo'][0]['ItinTotalFare']['EquivFare']['CurrencyCode'];

            // flight tag id
            $flightData['Itineraries'][$key]['TagID'] = $itinerary['TPA_Extensions']['TagID'];

            // baggage information
            $BaggageInformations =   $itinerary['AirItineraryPricingInfo'][0]['PTC_FareBreakdowns']['PTC_FareBreakdown'][0]['PassengerFare']
            ['TPA_Extensions']['BaggageInformationList']['BaggageInformation'];
            $baggegesData = $this->prepareBaggageData($BaggageInformations);
            // flight charge and allowance baggages
            $flightData['Itineraries'][$key]['allow'] = $baggegesData[0];
            $flightData['Itineraries'][$key]['charge'] = $baggegesData[1];
        }


        // get the search request data and prepare pagination part
        $flightData['RequestID'] = $result['RequestID'];
        $flightData['pages']['current_page_size'] = $result['Page']['Size'];
        $flightData['pages']['total_item'] = $result['Page']['TotalTags'];
        $flightData['pages']['offset'] = $result['Page']['Offset'];

        // saving the tag id to test process
        $tagID = new TagId;
        $tagID->tag_id = $result['RequestID'];
        $tagID->save();
        // end process of test
//        $flightData['RequestID'] = $result['RequestID'];
        //        // prepare pagination links
        //        $paginationLink = $result['Links'][3];
        //        dd($paginationLink);
        return $flightData;
    }

    // a function to format trip prices
    private function createFlightPrices($tripPricesArray, $flightData, $tripType, $withOfferPercentage = 0)
    {
        $flag = false;
        $flightPrices = [];
        $totalFareAfterOffer = 0;

        if ($withOfferPercentage) {
            $baseFare = $tripPricesArray['ItinTotalFare']['EquivFare']['Amount'];
            $taxes = $tripPricesArray['ItinTotalFare']['Taxes']['Tax'][0]['Amount'];
            $baseFareAfterOffer = (1 - $withOfferPercentage) * $baseFare;
            $totalFareAfterOffer = round($taxes + $baseFareAfterOffer, 2);
        }

        $flightPrices['ItinTotalFare'] = $tripPricesArray['ItinTotalFare']['TotalFare']['Amount'];
        $flightPrices['AdultPrice'] = $tripPricesArray['PTC_FareBreakdowns']['PTC_FareBreakdown'][0]['PassengerFare']['TotalFare']['Amount'];
        $flightPrices['AdultPriceWithOffer'] = ($withOfferPercentage) ? $totalFareAfterOffer : 0;
        $flightClassCode = $tripPricesArray['PTC_FareBreakdowns']['PTC_FareBreakdown'][0]['FareInfos']['FareInfo'][0]['TPA_Extensions']['Cabin']['Cabin'];
        $flightPrices['FareClassName'] = $this->getClassName($flightClassCode);

        // disable all increases due to business need
        $customAirlineFlag = false; //$this->checkCustomAirlineFlightOnly($flightData, $tripType);
        if($customAirlineFlag && (!$withOfferPercentage))
        {
            $baseFare = $tripPricesArray['ItinTotalFare']['EquivFare']['Amount'];
            $newBaseFare = round(0.90 * $baseFare, 2);
            $taxes = $tripPricesArray['ItinTotalFare']['Taxes']['Tax'][0]['Amount'];
            // discount of 5% here $customAirlinePercentage
            // $flightPrices['ItinTotalFare'] = $this->calculateDiscount($flightPrices['ItinTotalFare']); // will change for multiple passengers
            // $flightPrices['AdultPrice'] = $this->calculateDiscount($flightPrices['AdultPrice']);
            $newPrice = round( ($taxes + $newBaseFare), 2);
            $flightPrices['ItinTotalFare'] = $newPrice; // will change for multiple passengers
            $flightPrices['AdultPrice'] = $newPrice;
        }
        // dd($flightData, $customAirlineFlag);
            //    if ($flag) {
            //        $flightPrices['ItinTotalFare'] = $this->agencyFeesTotal($flightPrices['ItinTotalFare']);
            //        $flightPrices['AdultPrice'] = $this->agencyFeesTotal($flightPrices['AdultPrice']);
            //    }
        return $flightPrices;
    }

    // a function to calculate agency fees
    function agencyFeesTotal($totalFare)
    {
        switch ($totalFare) {
            case $totalFare <= 100:
                $agencyFees = 5;
                break;
            case $totalFare > 100 && $totalFare <= 250:
                $agencyFees = 7;
                break;
            case $totalFare > 250 && $totalFare <= 500:
                $agencyFees = 10;
                break;
            case $totalFare > 500 && $totalFare <= 1000:
                $agencyFees = 20;
                break;
            case $totalFare > 1000:
                $agencyFees = 25;
                break;
        }
        return round(1.03 * ($totalFare + $agencyFees), 2);
    }

    // a function to creat request body of the search
    private function createSearchRequestBody($request)
    {
        $tripType = $request->trip_type;
        $origin = $request->origin;
        $destination = $request->destination;
        $departureDate = $request->departure_date;


        if ($request->return_date) {
            $returnDate = $request->return_date;
        } else {
            $returnDate = null;
        }
        $numberOfTravellers = $request->travellers;
        $adult = $request->get('adult', 1);
        $youth = $request->get('youth', 0);
        $senior = $request->get('senior', 0);
        $child = $request->get('child', 0);
        $lap = $request->get('lap', 0);
        $seat = $request->get('seat', 0);
        $requestedClassName = $request->class;
        $numOfstops = $request->get('stops', null);


        $directFlightsOnlyFlag = false;
        if ($numOfstops != null && $numOfstops == '0') // check if we filter against 0 stops
        {
            $directFlightsOnlyFlag = true;
        }

        $searchBody = [
            'OTA_AirLowFareSearchRQ' => [
                'DirectFlightsOnly' => $directFlightsOnlyFlag,
                'AvailableFlightsOnly' => true,
                'Version' => '4.3.0',
                'POS' => [
                    'Source' => [
                        [
                            "PseudoCityCode"=> "Q32J",
                            'RequestorID' => [
                                'Type' => '1',
                                'ID' => '1',
                                'CompanyName' => [
                                    'Code' => 'TN',
                                    // 'content' => 'TN'
                                ]
                            ]
                        ]
                    ]
                ],

                'OriginDestinationInformation' => ($tripType == 'OpenJaw') ? $this->createMultiDestinationsFlight($origin, $destination, $departureDate) : $this->createFlights($origin, $destination, $departureDate, $returnDate),

                'TravelPreferences' => [
                    'ValidInterlineTicket' => true,
                    'Baggage' => ['Description' => true, 'RequestType' => 'C'],
                    'CabinPref' => [
                        [
                            'Cabin' => $this->getClassCode($requestedClassName),
                            'PreferLevel' => 'Preferred'
                        ],
                    ],
                    'VendorPrefPairing' => [
                        [
                            'PreferLevel' => 'Unacceptable',
                            'Applicability' => 'AtLeastOneSegment',
                            'VendorPref' => $this->unacceptable_airlines
                        ]
                    ],
                    'TPA_Extensions' => [
                        'TripType' => [
                            'Value' => $tripType
                        ],
                        'LongConnectTime' => [
                            'Min' => 780,
                            'Max' => 1200,
                            'Enable' => true
                        ],
                        'ExcludeCallDirectCarriers' => [
                            'Enabled' => true
                        ]
                    ]
                ],
                'TravelerInfoSummary' => [
                    'SeatsRequested' => [(int)$numberOfTravellers], // depend on front-end validation
                    'AirTravelerAvail' => [
                        [
                            'PassengerTypeQuantity' => $this->createPassengerTypeQuantityArray($adult, $youth, $senior, $child, $lap, $seat)
                        ],
                    ],
                    'PriceRequestInformation' => [
                        'TPA_Extensions' => [
                            'PointOfSaleOverride' => [
                                'Code' => 'USD'
                            ],
                            'Priority' => [
                                'Price' => [
                                    'Priority' => 1
                                ],
                                'DirectFlights' => [
                                    'Priority' => 2
                                ],
                                'Time' => [
                                    'Priority' => 3
                                ],
                                'Vendor' => [
                                    'Priority' => 4
                                ]
                            ]
                        ]
                    ],
                ],
                'TPA_Extensions' => [
                    'IntelliSellTransaction' => [
                        'RequestType' => [
                            'Name' => '50ITINS',

                        ]
                    ],

                ],

            ],
        ];

        if ($directFlightsOnlyFlag && $numOfstops == 0) {
            $searchBody['OTA_AirLowFareSearchRQ']['TravelPreferences']['MaxStopsQuantity'] = 0;
        }
        if ($numOfstops == 1) {
            $searchBody['OTA_AirLowFareSearchRQ']['TravelPreferences']['MaxStopsQuantity'] = 1;
        }
        //this function check if there's an airlines filter and adding it to search body
        if($request->airlines){
            $searchBody["OTA_AirLowFareSearchRQ"]["TravelPreferences"]["VendorPrefPairing"] = [[
                "PreferLevel" => "Preferred",
                "Applicability" => "AtLeastOneSegment",
                "VendorPref" =>  $this->createAirlinePreferredArray($request->airlines)
            ]];
        }
        // decode the request to json
        //$searchRequestBodyDecoded = json_decode(json_encode($searchBody));

        return $searchBody;
    }

    // this function is used to create flight arrays of Multi Destination Flight search
    protected function createMultiDestinationsFlight($origin, $destination, $departureDate)
    {
        // explode inputs to arrays
        $originArray = explode(',', $origin);
        $destinationArray = explode(',', $destination);
        $departureDateArray = explode(',', $departureDate);

        $RPHs = [];  // array that will contain flight data
        // index of flight numbers
        // $i = 1;
        $arrLength = count($originArray);
        // looping through arrays to create flights
        for ($i = 1; $i <= $arrLength; $i++) {
            $oneFlight = $this->createOneFlight($i, $originArray[$i - 1], $destinationArray[$i - 1], $departureDateArray[$i - 1]);
            array_push($RPHs, $oneFlight);
        }
        return $RPHs;
    }

    // this function is used to create flight arrays of one-way or return search
    public function createFlights($origin, $destination, $departureDate, $returnDate)
    {
        $RPHs = [];  // array that will contain flight data
        // index of flight numbers
        $i = 1;
        // first flight
        $first_flight = $this->createOneFlight($i, $origin, $destination, $departureDate);
        array_push($RPHs, $first_flight);
        // check if there is a return flight
        if ($returnDate) {
            // return flight
            // use the same function but use the return date
            $i = 2;
            $second_flight = $this->createOneFlight($i, $destination, $origin, $returnDate);
            array_push($RPHs, $second_flight);
        }
        return $RPHs;
    }

    // a function to create one flight data
    private function createOneFlight($i, $origin, $destination, $departureDate)
    {
        // $i is the index of flight (number of this flight)
        return [
            'RPH' => "$i",
            'DepartureDateTime' => DateTime::createFromFormat('Y-m-d', $departureDate)->format('Y-m-d\T00:00:00'),
            'OriginLocation' => [
                'LocationCode' => $origin
            ],
            'DestinationLocation' => [
                'LocationCode' => $destination
            ],
            'TPA_Extensions' => [
                'SegmentType' => [
                    'Code' => 'O'
                ],
            ]
        ];
    }

    // a function to create passengers types array
    public function createPassengerTypeQuantityArray($adult, $youth, $senior, $child, $lap, $seat)
    {
        // we will depend on validation at client side till now
        // adults and youth are the same in Sabre behaviour
        // array of class types :
        $passengersClassTypes = [];
        $totalAdults = $adult + $youth;
        if ($totalAdults) {
            $adt = [
                'Code' => 'ADT',
                'Quantity' => (int)$totalAdults
            ];
            array_push($passengersClassTypes, $adt);
        }
        if ($senior) {
            $sen = [
                'Code' => 'S65',
                'Quantity' => (int)$senior
            ];
            array_push($passengersClassTypes, $sen);
        }
        if ($child) {
            $childn = [
                'Code' => 'CNN',
                'Quantity' => (int)$child
            ];
            array_push($passengersClassTypes, $childn);
        }
        if ($lap) {
            $underLap = [
                'Code' => 'INF',
                'Quantity' => (int)$lap
            ];
            array_push($passengersClassTypes, $underLap);
        }
        if ($seat) {
            $underSeat = [
                'Code' => 'INS',
                'Quantity' => (int)$seat
            ];
            array_push($passengersClassTypes, $underSeat);
        }
        return $passengersClassTypes;
    }

    // a function to calculate the tickets class code
    public function getClassCode($className)
    {
        switch ($className) {
            case 'Premium first':
                $classCode = 'P';
                break;
            case 'First':
                $classCode = 'F';
                break;
            case 'Premium business':
                $classCode = 'J';
                break;
            case 'Business':
                $classCode = 'C';
                break;
            case 'Premium economy':
                $classCode = 'S';
                break;
            case 'Economy':
                $classCode = 'Y';
                break;
            default:
                $classCode = 'Y';
        }
        return $classCode;
    }

    // a function to prepare url of search
    private function prepareUrl()
    {
        // create uri
        $request_uri = '/v4.3.0/shop/flights?mode=live&enabletagging=true';
        // create url
        $url = Config::get('sabre.api_url', 'https://api-crt.cert.havail.sabre.com') . $request_uri;
        // return the created Url
        return $url;
    }

    // afunction to prepare pagination url
//    private function preparePaginationUrl($request)
//    {
//        $requestID = $request->requestID;
//        $requestedPage = $request->pageNo;
//        // dd($requestID, $requestedPage);
//        $offset = $this->calcOffset($requestedPage);
//        $limit = 10;
//        // create uri  BargainFinderMaxRQ~d124f7de-82e1-4877-b22f-e51f5998a00f?mode=live&limit=10&offset=11
//        $request_uri = "/v4.3.0/shop/flights/$requestID?mode=live&limit=$limit&offset=$offset";
//        // create url
//        $url = Config::get('sabre.api_url', 'https://api-crt.cert.havail.sabre.com') . $request_uri;
//        // return the created Url
//        return $url;
//    }

    // a function to calculate requested offset
    private function calcOffset($requestedPage)
    {
        $limit = 10;
        $calculatedOffset = ($limit * ($requestedPage - 1));
        return $calculatedOffset;
    }

    public function show($tagId, $tripType)
    {
        $url = Config::get('sabre.api_url', 'https://api-crt.cert.havail.sabre.com') . '/v4.3.0/shop/flights/tags/' . $tagId . '?mode=live';
        $result = app()->makeWith(CallApiTrait::class, ['url' => $url]);
        if (!$result instanceof \Throwable) {
            $tripFlightDataArray = $result['AirItinerary']['OriginDestinationOptions']['OriginDestinationOption'];
           // check if the first departure date is before tomorrow or not
            // return true if less than tomorrow
            if($this->checkDepartureDate($tripFlightDataArray[0]['FlightSegment'][0]['DepartureDateTime'])){
               return $this->apiResponse(null, 'Not Found', 404);
           }

            $BaggageInformations =   $result['AirItineraryPricingInfo'][0]['PTC_FareBreakdowns']['PTC_FareBreakdown'][0]['PassengerFare']
                                    ['TPA_Extensions']['BaggageInformationList']['BaggageInformation'];
//            return $BaggageInformations;
            $flightData['Itineraries'][0]['flights'] = ($tripType == 'OneWay') ? $this->createFlightSegmentsOneWayTrip($tripFlightDataArray) : $this->createFlightSegmentsMulti($tripFlightDataArray);
            $tripPricesArray = $result['AirItineraryPricingInfo'];
            $flightData['Itineraries'][0]['prices'] = $this->createFlightPrices($tripPricesArray[0], $flightData['Itineraries'][0]['flights'], $tripType);
           //price detials
            $flightData['Itineraries'][0]['taxfees']['Amount'] = $this->totalTax($result['AirItineraryPricingInfo'][0]['PTC_FareBreakdowns']['PTC_FareBreakdown']);
            $flightData['Itineraries'][0]['taxfees']['CurrencyCode'] = $result['AirItineraryPricingInfo'][0]['ItinTotalFare']['Taxes']['Tax'][0]['CurrencyCode'];
            // basefare = total price - total taxes
            $flightData['Itineraries'][0]['BaseFare']['Amount'] =  round($flightData['Itineraries'][0]['prices']['ItinTotalFare'] - $flightData['Itineraries'][0]['taxfees']['Amount'] ,2);
            $flightData['Itineraries'][0]['BaseFare']['CurrencyCode'] = $result['AirItineraryPricingInfo'][0]['ItinTotalFare']['EquivFare']['CurrencyCode'];

            $flightData['Itineraries'][0]['FareBasisCodes'] = $result['AirItineraryPricingInfo'][0]['PTC_FareBreakdowns']['PTC_FareBreakdown'][0]['FareBasisCodes']['FareBasisCode'];
            $flightData['Itineraries'][0]['Cabin'] = $result['AirItineraryPricingInfo'][0]['FareInfos']['FareInfo'][0]['TPA_Extensions']['Cabin']['Cabin'];


//            return $result;
            $passengersFareDataList = $result['AirItineraryPricingInfo'][0]['PTC_FareBreakdowns']['PTC_FareBreakdown'];
            $passengersData = $this->preparePassengersData($passengersFareDataList);
            $passengersData[0]['Price'] = $flightData['Itineraries'][0]['prices']['AdultPrice'];
            $flightData['Itineraries'][0]['passengerDetails'] = $passengersData; // to get MS percent
            // dd($passengersData);
            // prepare baggage information results
            $baggegesData = $this->prepareBaggageData($BaggageInformations);
            $flightData['Itineraries'][0]['allow'] = $baggegesData[0];
            $flightData['Itineraries'][0]['charge'] = $baggegesData[1];
            // end of baggage information results
            return $this->apiResponse($flightData, null, 200);
        }
        return $this->apiResponse(null, $result->getMessage(), $result->getCode());
    }

    // public function getAirlineName($airlineCode)
    // {
    //     $airlineName = $airlineCode;
    //     $url = Config::get('sabre.api_url', 'https://api-crt.cert.havail.sabre.com') . '/v1/lists/utilities/airlines?airlinecode=' . $airlineCode;
    //     $result = app()->makeWith(CallApiTrait::class, ['url' => $url]);
    //     if (!$result instanceof \Throwable) {
    //         $airlineInfo = $result['AirlineInfo'][0];
    //         if (isset($airlineInfo)) {
    //             $airlineName = $airlineInfo['AlternativeBusinessName'] ? $airlineInfo['AlternativeBusinessName'] : $airlineInfo['AirlineName'];
    //         }
    //     }
    //     // in all cases we will return a successfull output
    //     // may be the right airline name, or the code if there is no data.
    //     return $this->apiResponse($airlineName, null, 200);
    // }

    // a function to calculate the tickets class name
    private function getClassName($classCode)
    {
        switch ($classCode) {
            case 'P':
                $className = 'Premium first';
                break;
            case 'F':
                $className = 'First';
                break;
            case 'J':
                $className = 'Premium business';
                break;
            case 'C':
                $className = 'Business';
                break;
            case 'S':
                $className = 'Premium economy';
                break;
            case 'Y':
                $className = 'Economy';
                break;
            default:
                $className = 'Economy';
        }
        return $className;
    }

    // a function to get a search data airline companies
    public function get_airlines(Request $request)
    {
        // airlines Data
        $airlinesData = [];
        // read request id data:
        $requestId = $request->request_id;
        // prepare search url
        $request_uri = '/v4.3.0/shop/flights/' . $requestId . '?mode=live&limit=none&view=BFM_AIRLINES';
        $requestedUrl = Config::get('sabre.api_url', 'https://api-crt.cert.havail.sabre.com') . $request_uri;

        $result = app()->makeWith(CallApiTrait::class, ['url' => $requestedUrl]);
        if (!$result instanceof \Throwable) {
            $itinerariesData = $result['OTA_AirLowFareSearchRS']['PricedItineraries']['PricedItinerary'];
            foreach ($itinerariesData as $oneItinerary) {
                $flightSegment = $oneItinerary['AirItinerary']['OriginDestinationOptions']['OriginDestinationOption'][0]['FlightSegment'];
                foreach ($flightSegment as $oneFlight) {
                    $airlineCode = $oneFlight['OperatingAirline']['Code'];
                    if (!isset($airlinesData[$airlineCode])) {
                        $airlinesData[$airlineCode] = $this->getAirlineName($airlineCode);
                    }
                }
            }
            return $this->apiResponse($airlinesData, null, 200);
        }
        return $this->apiResponse(null, $result->getMessage(), $result->getCode());
    }

    // a function to prepare data of passengers
    private function preparePassengersData($passengersFareDataList)
    {
        $adultCount = 0;
        $childCount = 0;
        $infantLapCount = 0;
        $infantSeatCount = 0;
        $oneAdultPrice = 0;
        $oneChildPrice = 0;
        $oneInfantLapPrice = 0;
        $oneInfantSeatPrice = 0;
        $oneAdultTax = 0;
        $oneChildTax = 0;
        $oneInfantLapTax =0;
        $oneInfantSeatTax = 0;
        foreach ($passengersFareDataList as $oneFare) {
            $code = $oneFare['PassengerTypeQuantity']['Code'];
            switch ($code) {
                case 'S65':
                case 'ADT':
                    $adultCount += $oneFare['PassengerTypeQuantity']['Quantity'];
                    $oneAdultPrice = $oneFare['PassengerFare']['TotalFare']['Amount'];
                    $oneAdultTax = $this->netTaxAmount($oneFare['PassengerFare']['Taxes']['Tax']);
                break;
                case 'CNN':
                    $childCount += $oneFare['PassengerTypeQuantity']['Quantity'];
                    $oneChildPrice = $oneFare['PassengerFare']['TotalFare']['Amount'];
                    $oneChildTax = $this->netTaxAmount($oneFare['PassengerFare']['Taxes']['Tax']);
                    break;
                case 'INF':
                    $infantLapCount += $oneFare['PassengerTypeQuantity']['Quantity'];
                    $oneInfantLapPrice = $oneFare['PassengerFare']['TotalFare']['Amount'];
                    $oneInfantLapTax = $this->netTaxAmount($oneFare['PassengerFare']['Taxes']['Tax']);
                    break;
                case 'INS':
                    $infantSeatCount += $oneFare['PassengerTypeQuantity']['Quantity'];
                    $oneInfantSeatPrice = $oneFare['PassengerFare']['TotalFare']['Amount'];
                    $oneInfantSeatTax = $this->netTaxAmount($oneFare['PassengerFare']['Taxes']['Tax']);

                    break;
                default:
                    $adultCount++;
            }
        }
        $passengersData = [
            [
                "PassengerType" => "Adult",
                "PassengerTypeCode" => "ADT",
                "Quantity" => $adultCount,
                "Price" => $oneAdultPrice, // price oer one
                "Tax" => $oneAdultTax, // strip price is the fees of stripe service
                "BaseFare" => round($oneAdultPrice - $oneAdultTax,2),
            ],
            [
                "PassengerType" => "Child",
                "PassengerTypeCode" => "CNN",
                "Quantity" => $childCount,
                "Price" => $oneChildPrice, // price oer one
                "Tax" => $oneChildTax,
                "BaseFare" => round($oneChildPrice - $oneChildTax,2)

            ],
            [
                "PassengerType" => "Infant Lap",
                "PassengerTypeCode" => "INF",
                "Quantity" => $infantLapCount,
                "Price" => $oneInfantLapPrice, // price oer one
                "Tax" => $oneInfantLapTax,
                "BaseFare" => round($oneInfantLapPrice - $oneInfantLapTax,2)
            ],
            [
                "PassengerType" => "Infant Seat",
                "PassengerTypeCode" => "INS",
                "Quantity" => $infantSeatCount,
                "Price" => $oneInfantSeatPrice, // price oer one
                "Tax" => $oneInfantSeatTax,
                "BaseFare" => round($oneInfantSeatPrice - $oneInfantSeatTax,2)

            ],
            [
                "Fees" =>50,
            ]
        ];
        return $passengersData;
    }


    // a function to calculate travellers type
    private function travellerType($code)
    {
        switch ($code) {
            case 'ADT':
                $type = 'Adult';
                break;
            case 'S65':
                $type = 'Senior';
                break;
            case 'CNN':
                $type = 'Child';
                break;
            case 'INF':
                $type = 'Infant Lap';
                break;
            case 'INS':
                $type = 'Infant Seat';
                break;
            default:
                $type = 'Adult';
        }
        return $type;
    }


    // // a function to make the search with offer id
    // public function searchWithOffer(Request $request)
    // {
    //     // check for offer id ( existence and expiry date
    //     $offer = Offer::where('code', $request->offer)->first();
    //     // will add the validation after check
    //     if ($offer && $offer->activation && $offer->num_used < $offer->limit_uses && $offer->start_date->toDateString() <= Carbon::now()->toDateString() && $offer->expire_date->toDateString() > Carbon::now()->toDateString()) {
    //         // read data from offer and fill the request object with it to use it in search function
    //         $request->trip_type = $offer->type; // $offer->trip_type
    //         $request->origin = $offer->origin; // $offer->origin
    //         $request->destination = $offer->destination; // $offer->destination
    //         $request->departure_date = $offer->departure_date->toDateString(); // $offer->departure_date
    //         $request->return_date = (isset($offer->return_date)) ? $offer->return_date->toDateString() : null;//  $offer->return_date->toDateString(); // $offer->return_date
    //         $request->class = $offer->class; // $offer->class
    //         $request->airlines = $offer->airline->iata; // $offer->airlines
    //         // create url (static url till now)
    //         $url = $this->prepareUrl();
    //         // create body
    //         $searchRequestBody = $this->createSearchRequestBody($request);
    //         // call the api
    //         $result = app()->makeWith(CallApiTrait::class, ['url' => $url, 'body' => $searchRequestBody]);
    //         if (!$result instanceof \Throwable)  // check if result is not containing any exception or error
    //         {
    //             if ($offer->private_fare) {
    //                 // check for a private key flag
    //                 $flightWithPrivate = $this->checkFlightWithPrivateKey($result);
    //                 if (!$flightWithPrivate) {
    //                     return $this->apiResponse(null, "Offer has not private fare", 404);
    //                 }
    //                 $searchResultId = $flightWithPrivate;
    //             } else {
    //                 $searchResultId = $result['RequestID'] . '~1';
    //             }

    //             // call retrieve flight data by tag id (show function)
    //             $urlDetailsLink = Config::get('sabre.api_url', 'https://api-crt.cert.havail.sabre.com') . '/v4.3.0/shop/flights/tags/' . $searchResultId . '?mode=live';
    //             $resultDetails = app()->makeWith(CallApiTrait::class, ['url' => $urlDetailsLink]);
    //             if (!$resultDetails instanceof GuzzleException) {
    //                 $tripFlightDataArray = $resultDetails['AirItinerary']['OriginDestinationOptions']['OriginDestinationOption'];
    //                 $BaggageInformations =   $resultDetails['AirItineraryPricingInfo'][0]['PTC_FareBreakdowns']['PTC_FareBreakdown'][0]['PassengerFare']
    //                 ['TPA_Extensions']['BaggageInformationList']['BaggageInformation'];
    //                 $flightData['Itineraries'][0]['flights'] = ($request->trip_type == 'OneWay') ? $this->createFlightSegmentsOneWayTrip($tripFlightDataArray) : $this->createFlightSegmentsMulti($tripFlightDataArray);
    //                 $tripPricesArray = $resultDetails['AirItineraryPricingInfo'];
    //                 $withOfferPercentage = ($offer->offer_amount) / 100; // flag to use it in prepare new prices with offer
    //                 $flightData['Itineraries'][0]['prices'] = $this->createFlightPrices($tripPricesArray[0], $flightData['Itineraries'][0]['flights'], $request->trip_type, $withOfferPercentage);
    //                 $passengersFareDataList = $resultDetails['AirItineraryPricingInfo'][0]['PTC_FareBreakdowns']['PTC_FareBreakdown'];
    //                 $passengersData = $this->preparePassengersData($passengersFareDataList);
    //                 $flightData['Itineraries'][0]['passengerDetails'] = $passengersData;
    //                 $flightData['Itineraries'][0]['tagId'] = $searchResultId;
    //                 $flightData['Itineraries'][0]['tripType'] = $offer->type;
    //                 // prepare baggage information results
    //                 $baggegesData = $this->prepareBaggageData($BaggageInformations);
    //                 $flightData['Itineraries'][0]['allow'] = $baggegesData[0];
    //                 $flightData['Itineraries'][0]['charge'] = $baggegesData[1];
    //                 // end of baggage information results
    //                 return $this->apiResponse($flightData, null, 200);
    //             }
    //             // create output required:
    //             // $output = $this->formatResults($result, $tripType);
    //             return $this->apiResponse(null, $resultDetails->getResponse()->getReasonPhrase(), $result->getCode());
    //         }
    //         return $this->apiResponse(null, $result->getMessage(), $result->getCode());

    //     }
    //     return $this->apiResponse(null, "Offer has been expired", 404);
    // }

    private function createAirlinePreferredArray($airlines)
    {
        $airlinesPreferredList = [];

        if ($airlines) {
            // split the airlines to array
            $airlinesCodeList = explode(',', $airlines);
            // create airlines array:
            foreach ($airlinesCodeList as $code) {
                $airline = [];
                $airline['Code'] = $code;
                $airline['Type'] = "Operating";
                array_push($airlinesPreferredList, $airline);
            }


        }
        return $airlinesPreferredList;

    }

    public function airports(Request $request)
    {
        $airports = Airport::when($request->search,function($q) use($request){
            $q->where('iso','like','%'. $request->search.'%');
            $q->orwhere('iata','like','%'. $request->search.'%');
            $q->orwhere('name','like','%'. $request->search.'%');
        })->limit(10)->get();
        return $this->apiResponse($airports);
    }

}
