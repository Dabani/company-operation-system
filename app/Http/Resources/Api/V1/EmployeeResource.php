<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Resources\Json\JsonResource;

/** Sensitive fields (national ID, bank account) are never part of this resource - see EmployeeController::sensitive. */
class EmployeeResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id, 'employee_number' => $this->employee_number, 'first_name' => $this->first_name, 'last_name' => $this->last_name,
            'full_name' => $this->full_name, 'email' => $this->email, 'phone' => $this->phone, 'position_title' => $this->position_title,
            'category_id' => $this->category_id, 'category' => $this->whenLoaded('category', fn () => $this->category?->code),
            'status_id' => $this->status_id, 'status' => $this->whenLoaded('status', fn () => $this->status?->code),
            'department_id' => $this->department_id, 'department' => $this->whenLoaded('department', fn () => $this->department?->name),
            'manager_employee_id' => $this->manager_employee_id, 'manager' => $this->whenLoaded('manager', fn () => $this->manager?->full_name),
            'user_id' => $this->user_id, 'has_login' => $this->user_id !== null,
            'hired_on' => $this->hired_on?->toDateString(), 'ended_on' => $this->ended_on?->toDateString(),
        ];
    }
}
