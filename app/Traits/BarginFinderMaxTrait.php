<?php

namespace App\Traits;

use DateTime;
use Illuminate\Http\Request;
use App\Models\Airline;
use App\Models\Airport;
use Config;
use App\Models\TagId;
use Carbon\Carbon;

trait BarginFinderMaxTrait
{
    // format results of the search
    public function formatResults($result, $tripType)
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
            $flightData['Itineraries'][$key]['BaseFare']['Amount'] =  round($flightData['Itineraries'][$key]['prices']['ItinTotalFare'] - $flightData['Itineraries'][$key]['taxfees']['Amount'] ,2);
            $flightData['Itineraries'][$key]['BaseFare']['CurrencyCode'] = $itinerary['AirItineraryPricingInfo'][0]['ItinTotalFare']['EquivFare']['CurrencyCode'];


            // flight tag id
            $flightData['Itineraries'][$key]['TagID'] = $itinerary['TPA_Extensions']['TagID'];

            // baggage information
            $BaggageInformations = $itinerary['AirItineraryPricingInfo'][0]['PTC_FareBreakdowns']['PTC_FareBreakdown'][0]['PassengerFare']
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
        // $tagID = new TagId;
        // $tagID->tag_id = $result['RequestID'];
        // $tagID->save();
        // end process of test
//        $flightData['RequestID'] = $result['RequestID'];
        //        // prepare pagination links
        //        $paginationLink = $result['Links'][3];
        //        dd($paginationLink);
        return $flightData;
    }

    // a function to format trip prices
    public function createFlightPrices($tripPricesArray, $flightData, $tripType, $withOfferPercentage = 0)
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
        if ($customAirlineFlag && (!$withOfferPercentage)) {
            $baseFare = $tripPricesArray['ItinTotalFare']['EquivFare']['Amount'];
            $newBaseFare = round(0.90 * $baseFare, 2);
            $taxes = $tripPricesArray['ItinTotalFare']['Taxes']['Tax'][0]['Amount'];
            // discount of 5% here $customAirlinePercentage
            // $flightPrices['ItinTotalFare'] = $this->calculateDiscount($flightPrices['ItinTotalFare']); // will change for multiple passengers
            // $flightPrices['AdultPrice'] = $this->calculateDiscount($flightPrices['AdultPrice']);
            $newPrice = round(($taxes + $newBaseFare), 2);
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

    // a function to handle one way trip results
    public function createFlightSegmentsOneWayTrip($tripFlightDataArray)
    {
        $flightData = []; // an array that contain a one flight data
        $allAirlineNames = [];
        $allAirportNames = [];
        $flightsIndex = 0;
        foreach ($tripFlightDataArray[0]['FlightSegment'] as $flightSegment) {
            $flightData[$flightsIndex]['DepartureDateTime'] = $flightSegment['DepartureDateTime'];
            $flightData[$flightsIndex]['ArrivalDateTime'] = $flightSegment['ArrivalDateTime'];
            $flightData[$flightsIndex]['StopQuantity'] = $flightSegment['StopQuantity'];
            $flightData[$flightsIndex]['FlightNumber'] = $flightSegment['FlightNumber'];
            $flightData[$flightsIndex]['ElapsedTime'] = $flightSegment['ElapsedTime'];
            $flightData[$flightsIndex]['DepartureAirport'] = $flightSegment['DepartureAirport']['LocationCode'];
            $flightData[$flightsIndex]['ArrivalAirport'] = $flightSegment['ArrivalAirport']['LocationCode'];
            $flightData[$flightsIndex]['OperatingAirline'] = $flightSegment['OperatingAirline']['Code'];
            $airlineCode = $flightSegment['OperatingAirline']['Code'];
            $airportDepartureCode = $flightSegment['DepartureAirport']['LocationCode'];
            $airportArrivalCode = $flightSegment['ArrivalAirport']['LocationCode'];
            // get air line name from airline code
            if (!isset($allAirlineNames[$airlineCode])) {
                $allAirlineNames[$airlineCode] = $this->getAirlineName($airlineCode);
            }
            $airlineName = $allAirlineNames[$airlineCode];
            $flightData[$flightsIndex]['OperatingAirlineName'] = $airlineName;
            // end of getting air line name
            // get departure airport name
            if (!isset($allAirportNames[$airportDepartureCode])) {
                $allAirportNames[$airportDepartureCode] = $this->getAirportName($airportDepartureCode);
            }
            $airportDepartureName = $allAirportNames[$airportDepartureCode];
            $flightData[$flightsIndex]['DepartureAirportName'] = $airportDepartureName;
            // end get airport name
            // get arrival airport name
            if (!isset($allAirportNames[$airportArrivalCode])) {
                $allAirportNames[$airportArrivalCode] = $this->getAirportName($airportArrivalCode);
            }
            $airportArrivalName = $allAirportNames[$airportArrivalCode];
            $flightData[$flightsIndex]['ArrivalAirportName'] = $airportArrivalName;
            // end get arrival name
            // start add transit time
            if ($flightsIndex == 0) {
                $flightData[$flightsIndex]['FlightLayoverTime'] = 0;
            } else {
                $flightData[$flightsIndex]['FlightLayoverTime'] = $this->calcLayoverTime($flightData[$flightsIndex]['DepartureDateTime'], $flightData[$flightsIndex - 1]['ArrivalDateTime']);
            }
            // end adding transit time
            // increase flight index
            $flightsIndex++;
        }
        return array($flightData);
    }

    // a function to handle multiple destination trips( starting from Return type)
    public function createFlightSegmentsMulti($tripFlightDataArray)
    {
        $flightData = []; // an array that contain a one flight data
        $allAirlineNames = [];
        $allAirportNames = [];
        foreach ($tripFlightDataArray as $k => $flightSegmentVal) {
            $flightsIndex = 0;
            foreach ($flightSegmentVal['FlightSegment'] as $kk => $data) {
                $flightData[$k][$flightsIndex]['DepartureDateTime'] = $data['DepartureDateTime'];
                $flightData[$k][$flightsIndex]['ArrivalDateTime'] = $data['ArrivalDateTime'];
                $flightData[$k][$flightsIndex]['StopQuantity'] = $data['StopQuantity'];
                $flightData[$k][$flightsIndex]['FlightNumber'] = $data['FlightNumber'];
                $flightData[$k][$flightsIndex]['ElapsedTime'] = $data['ElapsedTime'];
                $flightData[$k][$flightsIndex]['DepartureAirport'] = $data['DepartureAirport']['LocationCode'];
                $flightData[$k][$flightsIndex]['ArrivalAirport'] = $data['ArrivalAirport']['LocationCode'];
                $flightData[$k][$flightsIndex]['OperatingAirline'] = $data['OperatingAirline']['Code'];
                $airportDepartureCode = $data['DepartureAirport']['LocationCode'];
                $airportArrivalCode = $data['ArrivalAirport']['LocationCode'];
                // get air line name from airline code
                $airlineCode = $data['OperatingAirline']['Code'];
                if (!isset($allAirlineNames[$airlineCode])) {
                    $allAirlineNames[$airlineCode] = $this->getAirlineName($airlineCode);
                }
                $airlineName = $allAirlineNames[$airlineCode];
                $flightData[$k][$flightsIndex]['OperatingAirlineName'] = $airlineName;
                // end of getting air line name
                // get departure airport name
                if (!isset($allAirportNames[$airportDepartureCode])) {
                    $allAirportNames[$airportDepartureCode] = $this->getAirportName($airportDepartureCode);
                }
                $airportDepartureName = $allAirportNames[$airportDepartureCode];
                $flightData[$k][$flightsIndex]['DepartureAirportName'] = $airportDepartureName;
                // end get airport name
                // get arrival airport name
                if (!isset($allAirportNames[$airportArrivalCode])) {
                    $allAirportNames[$airportArrivalCode] = $this->getAirportName($airportArrivalCode);
                }
                $airportArrivalName = $allAirportNames[$airportArrivalCode];
                $flightData[$k][$flightsIndex]['ArrivalAirportName'] = $airportArrivalName;
                // end get arrival name
                // start add transit time
                if ($flightsIndex == 0) {
                    $flightData[$k][$flightsIndex]['FlightLayoverTime'] = 0;
                } else {
                    $flightData[$k][$flightsIndex]['FlightLayoverTime'] = $this->calcLayoverTime($flightData[$k][$flightsIndex]['DepartureDateTime'], $flightData[$k][$flightsIndex - 1]['ArrivalDateTime']);
                }
                // end adding transit time
                // increase flight index
                $flightsIndex++;
            }
        }
        return $flightData;
    }

    // a function to creat request body of the search
    public function createSearchRequestBody($request)
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

        if ($request->airlines) {
            $airlines = $request->airlines;
        } else {
            $airlines = null;
        }
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
                            'PseudoCityCode' => 'Q32J',
                            'RequestorID' => [
                                'Type' => '1',
                                'ID' => '1',
                                'CompanyName' => [
                                    'Code' => 'TN',
                                    'content' => 'TN'
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
                            'Name' => '50ITINS'
                        ]
                    ]
                ]
            ],
        ];

        if ($directFlightsOnlyFlag && $numOfstops == 0) {
            $searchBody['OTA_AirLowFareSearchRQ']['TravelPreferences']['MaxStopsQuantity'] = 0;
        }
        if ($numOfstops == 1) {
            $searchBody['OTA_AirLowFareSearchRQ']['TravelPreferences']['MaxStopsQuantity'] = 1;
        }

        if ($airlines) {
            $airlinesPreferedList = [];
            // split the airlines to array
            $airlinesCodeList = explode(',', $airlines);
            // create airlines array:
            foreach ($airlinesCodeList as $code) {
                $airline = [];
                $airline['Code'] = $code;
                $airline['Type'] = "Operating";
                array_push($airlinesPreferedList, $airline);
            }

            $searchBody["OTA_AirLowFareSearchRQ"]["TravelPreferences"]["VendorPrefPairing"] = [[
                "PreferLevel" => "Preferred",
                "Applicability" => "AtLeastOneSegment",
                "VendorPref" => $airlinesPreferedList
            ]];
        }

        // decode the request to json
        $searchRequestBodyDecoded = json_decode(json_encode($searchBody));

        return $searchRequestBodyDecoded;
    }

    // this function is used to create flight arrays of Multi Destination Flight search
    public function createMultiDestinationsFlight($origin, $destination, $departureDate)
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
    public function createOneFlight($i, $origin, $destination, $departureDate)
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
                ]
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
    public function prepareUrl()
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
    public function calcOffset($requestedPage)
    {
        $limit = 10;
        $calculatedOffset = ($limit * ($requestedPage - 1));
        return $calculatedOffset;
    }

    public function getAirlineName($airlineCode)
    {
        $airlineName = $airlineCode;
        // get airlinename data
        $airLineData = Airline::whereIata($airlineCode)->first();
        if ($airLineData) {
            $airlineName = $airLineData->name;
        }
        return $airlineName;
    }

    public function getAirportName($airportCode)
    {
        $airportName = $airportCode;
        // get airportName data
        $airportData = Airport::whereIata($airportCode)->first();
        if ($airportData) {
            $airportName = $airportData->name;
        }
        return $airportName;

    }

    // a function to calculate the tickets class name
    public function getClassName($classCode)
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

    // a function to prepare data of passengers
    public function preparePassengersData($passengersFareDataList)
    {
        $adultCount = 0;
        $childCount = 0;
        $infantLapCount = 0;
        $infantSeatCount = 0;
        $oneAdultPrice = 0;
        $oneChildPrice = 0;
        $oneInfantLapPrice = 0;
        $oneInfantSeatPrice = 0;
        foreach ($passengersFareDataList as $oneFare) {
            $code = $oneFare['PassengerTypeQuantity']['Code'];
            switch ($code) {
                case 'S65':
                case 'ADT':
                    $adultCount += $oneFare['PassengerTypeQuantity']['Quantity'];
                    $oneAdultPrice = $oneFare['PassengerFare']['TotalFare']['Amount'];
                    break;
                case 'CNN':
                    $childCount += $oneFare['PassengerTypeQuantity']['Quantity'];
                    $oneChildPrice = $oneFare['PassengerFare']['TotalFare']['Amount'];
                    break;
                case 'INF':
                    $infantLapCount += $oneFare['PassengerTypeQuantity']['Quantity'];
                    $oneInfantLapPrice = $oneFare['PassengerFare']['TotalFare']['Amount'];
                    break;
                case 'INS':
                    $infantSeatCount += $oneFare['PassengerTypeQuantity']['Quantity'];
                    $oneInfantSeatPrice = $oneFare['PassengerFare']['TotalFare']['Amount'];
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
                "Price" => $oneAdultPrice  // price oer one
            ],
            [
                "PassengerType" => "Child",
                "PassengerTypeCode" => "CNN",
                "Quantity" => $childCount,
                "Price" => $oneChildPrice // price oer one
            ],
            [
                "PassengerType" => "Infant Lap",
                "PassengerTypeCode" => "INF",
                "Quantity" => $infantLapCount,
                "Price" => $oneInfantLapPrice // price oer one
            ],
            [
                "PassengerType" => "Infant Seat",
                "PassengerTypeCode" => "INS",
                "Quantity" => $infantSeatCount,
                "Price" => $oneInfantSeatPrice // price oer one
            ]
        ];
        return $passengersData;
    }

    // a function to calculate travellers type
    public function travellerType($code)
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

    // a function to get the results of search an offer with private fare key
    public function checkFlightWithPrivateKey($result)
    {
        $itineraries = $result['OTA_AirLowFareSearchRS']['PricedItineraries']['PricedItinerary'];

        foreach ($itineraries as $key => $itinerary) {
            $tripPricesArray = $itinerary['AirItineraryPricingInfo'];
            if (isset($tripPricesArray[0]['PrivateFareType'])) {
                $itineraryTagId = $itinerary['TPA_Extensions']['TagID'];
                return $itineraryTagId;
            }
        }
        return 0; // 0 if we could not find any trip with data of PrivateFareType
    }

    // a function to prepare baggage information
    public function prepareBaggageData($BaggageInformations)
    {
        $Allowance = [];
        $Charge = [];
        foreach ($BaggageInformations as $index => $BaggageInformation) {
            if ($BaggageInformation['ProvisionType'] == 'A') {
                if (isset($BaggageInformation['Allowance'])) {
                    $Segments = $BaggageInformation['Segment'];
                    foreach ($Segments as $segment) {
                        $Allowance[$segment['Id']] = $BaggageInformation['Allowance'];
                    }
                }
            } elseif ($BaggageInformation['ProvisionType'] == 'C') {
                if (isset($BaggageInformation['Charge'])) {
                    $Segments = $BaggageInformation['Segment'];
                    foreach ($Segments as $segment) {
                        $Charge[$segment['Id']][] = $BaggageInformation['Charge'];
                    }
                }
            }
        }
        return [$Allowance, $Charge];
    }

    public function checkCustomAirlineFlightOnly($flightData, $tripType)
    {
        foreach ($flightData as $data) {
            // if($tripType == 'OneWay')
            // {
            //     foreach ($data as $key => $value) {

            //         if ($value['OperatingAirline'] != 'MS') {
            //             // $flag = true;
            //             return false;
            //         }
            //     }
            // } else {
            foreach ($data as $key => $value) {

                if ($value['OperatingAirline'] != 'MS') {
                    // $flag = true;
                    return false;
                }
            }
        }
        // }
        return true;
    }

    // a function to calculate transit (layover time) of a flight
    private function calcLayoverTime($DepartureDateTime, $ArrivalDateTime)
    {
        return Carbon::parse($DepartureDateTime)->diffInMinutes(Carbon::parse($ArrivalDateTime));
    }

//    // a function to calculate discount on Custom airline
//    public function calculateDiscount()
//    {
//        $customAirlinePercentage;
//    }



    //function to calculate net tax amount
    public function netTaxAmount($taxes){
        $netTax = 0;
        $additionalFees =0;
    foreach($taxes as $tax){

        if(isset($tax['CountryCode']) && !empty($tax['CountryCode'])){
            $netTax +=$tax['Amount'];
        }else{
            $additionalFees +=$tax['Amount'];
            }
       }
    return round($netTax,2);

}
    public function totalTax($taxes)
    {
        $taxesResult = 0;
        foreach($taxes as $tax){
            $output = $this->netTaxAmount($tax['PassengerFare']['Taxes']['Tax']);
            $taxesResult+= $output * $tax['PassengerTypeQuantity']['Quantity'];
        }

        return round($taxesResult,2);
    }
}
