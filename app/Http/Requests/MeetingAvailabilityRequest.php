<?php

namespace App\Http\Requests;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class MeetingAvailabilityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'date_from' => ['required', 'date_format:Y-m-d'],
            'date_to' => ['required', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'timezone' => ['required', 'timezone:all'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            $from = CarbonImmutable::createFromFormat('!Y-m-d', $this->input('date_from'));
            $to = CarbonImmutable::createFromFormat('!Y-m-d', $this->input('date_to'));
            if ($from !== false && $to !== false && $from->diffInDays($to) > 30) {
                $validator->errors()->add('date_to', 'Availability can be requested for at most 31 days.');
            }
        }];
    }
}
