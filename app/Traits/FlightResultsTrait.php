<?php

namespace App\Traits;
use App\Models\Airline;
use App\Models\Airport;
use Carbon\Carbon;

trait FlightResultsTrait
{
    // a function to check the departure date is after tomorrow
    public function checkDepartureDate($DepartureDate)
    {
        $DepartureDates = explode(',',$DepartureDate);
        $countError = 0;

        foreach ($DepartureDates as $DepartureDate) {
            if (date("Y-m-d", strtotime($DepartureDate)) < Carbon::tomorrow()) {
                $countError++;
            }
        }

        if($countError>0) {
            return true;
        }else{
            return false;
        }
    }

}
