<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * HR letter templates (appointment, offer, NOC, relieving, experience …): HR writes the wording once with
 * placeholders like {employee_name}; a letter is then made for an employee, saved as a PDF in their documents.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('letter_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            // The document type the issued letter is saved as (appointment, offer, noc, relieving, experience_issued, hr_letter).
            $table->string('category', 40)->default('hr_letter');
            $table->longText('body');
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['category', 'is_active']);
        });

        $now = now();
        foreach (self::templates() as $template) {
            DB::table('letter_templates')->insert([...$template, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('letter_templates');
    }

    /** The five standard letters for Soham Web Solution (HR can change the wording later). */
    public static function templates(): array
    {
        return [
            ['name' => 'Offer letter', 'category' => 'offer', 'body' => implode('', [
                '<p>Date: {today}</p>',
                '<p><strong>{employee_name}</strong><br>{employee_address}</p>',
                '<p><strong>Subject: Offer of employment</strong></p>',
                '<p>Dear {first_name},</p>',
                '<p>Thank you for the time you spent with us during the selection process. We are pleased to offer you the position of <strong>{designation}</strong> in the <strong>{department}</strong> department at {company_name}.</p>',
                '<ol>',
                '<li><strong>Date of joining:</strong> {joining_date}.</li>',
                '<li><strong>Salary:</strong> {monthly_salary} per month ({annual_salary} per year), paid monthly after statutory deductions as applicable.</li>',
                '<li><strong>Reporting to:</strong> {manager_name}.</li>',
                '<li><strong>Probation:</strong> you will be on probation until {probation_end_date}.</li>',
                '</ol>',
                '<p>This offer is subject to the verification of your documents and references. A detailed appointment letter with the full terms of employment will be given to you on joining.</p>',
                '<p>Please sign and return a copy of this letter within 7 days to confirm your acceptance. We look forward to welcoming you to the team.</p>',
                '<p>Yours sincerely,</p>',
                '<p>{signature}</p>',
                '<p><strong>Acceptance</strong><br>I accept the offer on the terms stated above.</p>',
                '<p>Signature: ____________________ &nbsp; Date: ______________</p>',
            ])],
            ['name' => 'Appointment letter', 'category' => 'appointment', 'body' => implode('', [
                '<p>Date: {today}</p>',
                '<p><strong>{employee_name}</strong><br>Employee code: {employee_code}<br>{employee_address}</p>',
                '<p><strong>Subject: Letter of appointment</strong></p>',
                '<p>Dear {first_name},</p>',
                '<p>With reference to your application and the interviews you had with us, we are pleased to appoint you as <strong>{designation}</strong> in the <strong>{department}</strong> department of {company_name} with effect from <strong>{joining_date}</strong>, on the following terms and conditions:</p>',
                '<ol>',
                '<li><strong>Salary:</strong> your gross salary will be {monthly_salary} per month ({annual_salary} per year), subject to statutory deductions as applicable.</li>',
                '<li><strong>Probation:</strong> you will be on probation until {probation_end_date}. On satisfactory completion your employment will be confirmed in writing.</li>',
                '<li><strong>Reporting:</strong> you will report to {manager_name}, or to any other person the management names.</li>',
                '<li><strong>Working hours and leave:</strong> you will follow the attendance, working hours and leave policies of the company, as updated from time to time.</li>',
                '<li><strong>Notice period:</strong> either side may end this employment by giving {notice_period} of written notice, or salary in place of the notice.</li>',
                '<li><strong>Confidentiality:</strong> you will not share any confidential information, source code, client data or business information of the company or its clients, during or after your employment.</li>',
                '<li><strong>Company property:</strong> all equipment, documents and work you produce belong to the company and must be returned when you leave.</li>',
                '</ol>',
                '<p>Please sign and return a copy of this letter as your acceptance of these terms. We welcome you to {company_name} and wish you a rewarding career with us.</p>',
                '<p>Yours sincerely,</p>',
            ])],
            ['name' => 'NOC (No Objection Certificate)', 'category' => 'noc', 'body' => implode('', [
                '<p>Date: {today}</p>',
                '<p style="text-align:center"><strong>TO WHOMSOEVER IT MAY CONCERN</strong></p>',
                '<p><strong>Subject: No Objection Certificate</strong></p>',
                '<p>This is to certify that <strong>{employee_name}</strong> (employee code {employee_code}) has been working with {company_name} as <strong>{designation}</strong> in the {department} department since {joining_date}.</p>',
                '<p>The company has no objection to {first_name} applying for [write the purpose here, e.g. a passport / visa / higher studies].</p>',
                '<p>This certificate is issued at the request of the employee and does not make the company responsible or liable in any way.</p>',
                '<p>Yours faithfully,</p>',
            ])],
            ['name' => 'Relieving letter', 'category' => 'relieving', 'body' => implode('', [
                '<p>Date: {today}</p>',
                '<p><strong>{employee_name}</strong><br>Employee code: {employee_code}<br>{employee_address}</p>',
                '<p><strong>Subject: Relieving letter</strong></p>',
                '<p>Dear {first_name},</p>',
                '<p>This is with reference to your resignation dated {resignation_date}. Your resignation has been accepted and you are relieved from your duties as <strong>{designation}</strong> at {company_name} at the close of working hours on <strong>{exit_date}</strong>.</p>',
                '<p>We confirm that you have handed over your responsibilities and company property. Your full and final settlement will be made as per company policy.</p>',
                '<p>We thank you for your contribution during your time with us and wish you every success in the future.</p>',
                '<p>Yours sincerely,</p>',
            ])],
            ['name' => 'Experience letter', 'category' => 'experience_issued', 'body' => implode('', [
                '<p>Date: {today}</p>',
                '<p style="text-align:center"><strong>TO WHOMSOEVER IT MAY CONCERN</strong></p>',
                '<p><strong>Subject: Experience certificate</strong></p>',
                '<p>This is to certify that <strong>{employee_name}</strong> (employee code {employee_code}) worked with {company_name} from <strong>{joining_date}</strong> to <strong>{exit_date}</strong>. At the time of leaving, {first_name} was working as <strong>{designation}</strong> in the {department} department.</p>',
                '<p>During this time we found {first_name} sincere, hard-working and dedicated to the work. Their conduct with colleagues and clients was good.</p>',
                '<p>We wish {first_name} every success in the future.</p>',
                '<p>Yours faithfully,</p>',
            ])],
        ];
    }
};
