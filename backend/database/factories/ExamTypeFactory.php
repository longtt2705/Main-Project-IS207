<?php

namespace Database\Factories;

use App\Models\ExamType;
use App\Models\Hospital;
use App\Models\HospitalDepartment;
use App\Models\Specialty;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ExamType>
 */
class ExamTypeFactory extends Factory
{
    protected $model = ExamType::class;

    public function configure(): static
    {
        return $this->afterCreating(function (ExamType $exam) {
            if (! $exam->hospital_department_id) {
                $specialty = Specialty::firstOrCreate(['name' => $exam->name]);
                $department = HospitalDepartment::firstOrCreate([
                    'hospital_id' => $exam->hospital_id, 'specialty_id' => $specialty->id,
                ]);
                $exam->update(['hospital_department_id' => $department->id]);
            }
        });
    }

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'hospital_id' => Hospital::factory(),
            'name' => fake()->randomElement([
                'Kham tong quat',
                'Xet nghiem mau co ban',
                'Sieu am bung tong quat',
                'Chup X-quang nguc',
                'Chup cong huong tu MRI',
            ]),
            'price' => fake()->numberBetween(100000, 1500000),
        ];
    }
}
