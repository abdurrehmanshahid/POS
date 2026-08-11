<?php

namespace App\Http\Controllers;

use App\Models\Challan;
use App\Models\Setting;
use App\Support\DocumentResponse;
use App\Support\Download;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The fee challan as a PDF (spec §12), rendered from the live record.
 *
 * Landscape A4: the voucher prints three copies side by side — Bank, Student,
 * Institute — which is the form every fee challan in Pakistan is printed on and
 * the order they are torn off at the bank counter. Three portrait columns would
 * be 60mm wide and unreadable.
 *
 * THREE routes, ONE document. That second half is the point. There is a single
 * renderer — dompdf, over `challans.pdf` — and every way of reaching the
 * voucher reaches those same bytes:
 *
 *   view()      the PDF.js viewer, pointed at stream(). Read it, print it,
 *               hand it over. Nothing lands in Downloads.
 *   stream()    the bytes, displayed. What the viewer fetches.
 *   download()  the bytes, saved, for attaching to an email or WhatsApp.
 *
 * The alternative was a hand-written HTML twin of the voucher for the screen,
 * with dompdf producing the PDF for download. Two typesetters for one financial
 * document drift apart — dompdf supports a subset of CSS, so tidying the screen
 * with a flex layout silently changes the copy the parent is holding, and
 * nobody sees it happen. One renderer cannot disagree with itself.
 */
class ChallanController extends Controller
{
    /**
     * The voucher on screen, in a viewer that is always there.
     *
     * Serving the PDF `inline` and trusting the browser is what this replaces:
     * "inline" is a request a browser may decline, and where it declines it
     * downloads instead, so the button labelled "view" was the one button whose
     * promise could not be kept. See `resources/views/documents/viewer.blade.php`.
     */
    public function view(Request $request, Challan $challan): Response
    {
        // No eager load. This page renders a title and an iframe, and for a
        // SINGLE model `->load()` costs exactly the query that touching the
        // property would — there is no N+1 to prevent with one record. Priming
        // `admission` here measured 4 queries against 1, because `isVisibleTo()`
        // returns early for anyone with `scope.all` and never reads it.
        $this->assertVisible($request, $challan);

        return DocumentResponse::viewer(
            'Fee challan '.$challan->challan_no.' · '.$challan->student?->name,
            route('challans.stream', $challan),
            route('challans.pdf', $challan),
        );
    }

    public function stream(Request $request, Challan $challan): Response
    {
        return Download::inline($this->render($request, $challan), $this->filename($challan));
    }

    public function download(Request $request, Challan $challan): Response
    {
        return Download::named($this->render($request, $challan), $this->filename($challan));
    }

    private function filename(Challan $challan): string
    {
        return 'challan-'.$challan->challan_no.'.pdf';
    }

    private function render(Request $request, Challan $challan): Response
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

        $this->assertVisible($request, $challan);

        return DocumentResponse::pdf('challans.pdf', [
            'challan' => $challan,
            'settings' => Setting::current(),
        ], 'a4');
    }

    /**
     * One permission check, asked the same way by all three routes.
     *
     * Its own method so the check cannot be present on one route and missing
     * from another. That is the failure mode worth designing against here:
     * every extra way to reach a document is another place to forget who is
     * allowed to see it, and `stream()` is reached by a fetch from inside a
     * viewer rather than by a click, which makes it the easiest one to overlook.
     */
    private function assertVisible(Request $request, Challan $challan): void
    {
        // Officers may only reach their own enrolments (spec §6). Asked of the
        // invoice: `$challan->admission->enrolled_by` is a 500 rather than a
        // denial on a charge, which has no admission to ask.
        if (! $challan->isVisibleTo($request->user())) {
            abort(403);
        }
    }
}
