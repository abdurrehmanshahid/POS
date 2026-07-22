<?php

namespace App\Http\Controllers;

use App\Models\Challan;
use App\Models\Setting;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ChallanController extends Controller
{
    /** Stream the A4 fee challan as a PDF (spec §12), rendered from the live record. */
    public function download(Request $request, Challan $challan): Response
    {
        $challan->load('admission.student', 'admission.course.trainer', 'admission.enroller', 'discountApprover', 'installments');

        // Officers may only download their own enrolments (spec §6).
        if (! $request->user()->can('scope.all') && $challan->admission->enrolled_by !== $request->user()->id) {
            abort(403);
        }

        $pdf = Pdf::loadView('challans.pdf', [
            'challan' => $challan,
            'settings' => Setting::current(),
        ])->setPaper('a4');

        return $pdf->download('challan-'.$challan->challan_no.'.pdf');
    }
}
