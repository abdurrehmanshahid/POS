<?php

namespace App\Http\Controllers;

use App\Models\Challan;
use App\Models\Setting;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as PdfDocument;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ChallanController extends Controller
{
    /**
     * The fee challan as a PDF (spec §12), rendered from the live record.
     *
     * Landscape A4: the voucher prints three copies side by side, one each for
     * the student, head office and the campus, matching the form the institute
     * already hands over the counter. Three portrait columns would be 60mm wide
     * and unreadable.
     *
     * Two ways out, because a counter wants two different things from the same
     * document and only one of them was on offer:
     *
     *   view()      opens in the browser's own PDF viewer. Read it, check it,
     *               hit Ctrl+P, hand it over. Nothing lands in Downloads.
     *   download()  saves the file, for attaching to an email or WhatsApp.
     *
     * Printing used to mean download, find the file, open it, print, and then
     * remember to delete it — five steps and a Downloads folder full of
     * vouchers for the most common thing anyone does with this document.
     */
    public function view(Request $request, Challan $challan): Response
    {
        return $this->render($request, $challan)
            ->stream('challan-'.$challan->challan_no.'.pdf');
    }

    public function download(Request $request, Challan $challan): Response
    {
        return $this->render($request, $challan)
            ->download('challan-'.$challan->challan_no.'.pdf');
    }

    /**
     * Authorise, load and typeset — the part both routes share.
     *
     * One method, so the permission check cannot be present on one route and
     * missing on the other. That is the failure mode worth designing against
     * here: a second way to reach a document is a second place to forget who
     * is allowed to see it.
     */
    private function render(Request $request, Challan $challan): PdfDocument
    {
        $challan->load(
            'student',
            'raiser',
            'admission.student', 'admission.course.trainer', 'admission.cohort',
            // `admissions.course` (plural) as well as the anchor's: the voucher
            // lists every course on the invoice, and without this it lazy-loads
            // one query per course while rendering the PDF.
            'admission.enroller', 'admissions.course', 'discountApprover', 'installments', 'payments',
        );

        // Officers may only reach their own enrolments (spec §6). Asked of the
        // invoice: `$challan->admission->enrolled_by` is a 500 rather than a
        // denial on a charge, which has no admission to ask.
        if (! $challan->isVisibleTo($request->user())) {
            abort(403);
        }

        return Pdf::loadView('challans.pdf', [
            'challan' => $challan,
            'settings' => Setting::current(),
        ])->setPaper('a4', 'landscape');
    }
}
