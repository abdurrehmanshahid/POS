<?php

namespace App\Http\Controllers;

use App\Models\Challan;
use App\Models\Setting;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ChallanController extends Controller
{
    /**
     * Stream the fee challan as a PDF (spec §12), rendered from the live record.
     *
     * Landscape A4: the voucher prints three copies side by side, one each for
     * the student, head office and the campus, matching the form the institute
     * already hands over the counter. Three portrait columns would be 60mm wide
     * and unreadable.
     */
    public function download(Request $request, Challan $challan): Response
    {
        $challan->load(
            'admission.student', 'admission.course.trainer', 'admission.cohort',
            'admission.enroller', 'discountApprover', 'installments', 'payments',
        );

        // Officers may only download their own enrolments (spec §6).
        if (! $request->user()->can('scope.all') && $challan->admission->enrolled_by !== $request->user()->id) {
            abort(403);
        }

        $pdf = Pdf::loadView('challans.pdf', [
            'challan' => $challan,
            'settings' => Setting::current(),
        ])->setPaper('a4', 'landscape');

        return $pdf->download('challan-'.$challan->challan_no.'.pdf');
    }
}
