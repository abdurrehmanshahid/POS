# Frontend conventions. BBT Institute Management System

Read `resources/views/livewire/pages/dashboard.blade.php`, `resources/views/livewire/notifications-bell.blade.php`,
and `resources/css/app.css` for live examples before writing. Match that style exactly.

## Component shape
- Every screen is a **Volt full-page component** at `resources/views/livewire/pages/<name>.blade.php`.
  Routes already exist (do NOT edit routes). The default layout `components.layouts.app` is applied
  automatically, do NOT add a `#[Layout]` attribute.
- File format (Volt anonymous class):
  ```blade
  <?php
  use Livewire\Volt\Component;
  new class extends Component {
      public function with(): array { return [ /* view data */ ]; }
      // public action methods here
  }; ?>
  <div class="container-app anim-fade">  {{-- single root element, this wrapper --}}
    ...
  </div>
  ```
- The permission middleware already guards the route; still call `auth()->user()->can('key')` in the
  view to hide buttons/sections the way the prototype does.

## Money & dates
- All money is **integer PKR**. Format with `\App\Support\Format::money($int)` → `Rs 1,234`.
- Dates: `\App\Support\Format::date($carbonOrString)` → `15 Jul 2026`. Initials: `Format::initials($name)`.

## Shared Blade components
- Icons: `<x-icon name="plus" :size="17" />`. Available names: dashboard, registrations, challans, courses,
  students, staff, reports, datamodel, settings, bell, plus, search, check, check-circle, alert, alert-circle,
  chevron-right, chevron-down, chevron-up, arrow-right, arrow-left, signin, logout, lock, x, edit, download,
  menu, spark, users, clock, book.
- Pills: `<x-ui.pill tone="paid" :dot="true">Paid</x-ui.pill>`. Tones: paid, unpaid, overdue, cancelled,
  validated, navy, orange, iris.
- Avatar: `<x-ui.avatar :name="$student->name" variant="orange" :size="30" />`. variants: default, navy, orange.

## CSS classes (from app.css, use these, avoid ad-hoc inline where a class exists)
- Buttons: `.btn` + `.btn-primary` (navy) / `.btn-accent` (orange) / `.btn-ghost` / `.btn-danger`; `.btn-sm`; `.btn-icon`.
- Cards: `.card`, `.panel` + `.panel-head` + `.panel-title`.
- Inputs: `.label`, `.input`, `.select`, `.textarea`, `.field-error`, `.search` (wrap input+icon).
- Tables: `.table` (thead/th/td styled). Add `class="clickable"` to `<tr>` for hover+pointer; `.row-cancelled` for 50% opacity; `.empty-state` for the empty message row/cell. `th.right`/`td.right` for right align.
- KPI: `.kpi`, `.kpi-num` (add `.tnum`), `.kpi-label`. Grids: `.grid-3`, `.grid-2`, `.split`.
- Bars: `<div class="bar"><span style="width:60%;background:..."></span></div>`.
- Numbers/IDs: add class `tnum` for tabular figures.

## Drawer (right sheet) pattern. Alpine-driven open state in the component
```blade
<div x-data="{ open: @entangle('drawerOpen') }">
  <template x-if="open">
    <div>
      <div class="drawer-backdrop" @click="open=false"></div>
      <div class="drawer">
        <div class="drawer-head"> ...title... <button class="btn-icon" @click="open=false"><x-icon name="x" :size="18"/></button></div>
        <div class="drawer-body"> ...content (server-rendered from a selected model)... </div>
        <div class="drawer-foot"> ...actions... </div>
      </div>
    </div>
  </template>
</div>
```
Prefer selecting a record server-side via a Livewire method (`wire:click="select({{ $id }})"`) that sets a
public property and opens the drawer, then render its details from that property in `with()`.

## Dialog (confirm) pattern
Use `.dialog-backdrop` > `.dialog` > `.dialog-body`. A confirm dialog may include a required reason
`<textarea class="textarea">` and/or a payment-method chooser (chips). Block confirm until valid.

## Toasts
From a Livewire action: `$this->dispatch('bbt-toast', tone: 'ok', title: 'Saved', msg: 'WD-101 updated');`
Tones: ok, info, warn, err. The layout's toast host renders them (no work needed on your side).
For redirect flows use `session()->flash('toast', ['tone'=>'ok','title'=>'..','msg'=>'..']);`.

## Model / service APIs
- **Course**: `code,title,fee(int),capacity(?int),is_active(bool)`; `trainer()`→Teacher(`name`,`phone`); `admissions()`;
  `seatsUsed():int`; `isFull():bool`; `seatsLeft():?int`.
- **Teacher**: `name`, `phone`.
- **Student**: `student_code,type('R'|'T'),name,guardian_name,cnic,phone,created_at`; `admissions()`; `creator()`;
  `initials()`; `typeLabel()`('Regular'|'Track'); scope `Student::visibleTo($user)`.
- **Admission**: `reg_no,status('validated'|'pending'|'cancelled')`; `student(),course(),enroller()`(User),`challan()`;
  scopes `visibleTo($user)`, `active()`.
- **Challan**: `challan_no,base_amount,discount_amount,net_amount(int),discount_reason,plan,due_date(Carbon),`
  `status('unpaid'|'paid'),paid_via,paid_at`; `admission(),discountApprover()`(User),`installments(),auditLogs()`;
  `isPaid()`, `isOverdue()`, `paymentState()`('paid'|'overdue'|'unpaid').
- **Role**: `id(string),name,is_system,tone('navy'|'orange')`; `permissions()`(RolePermission w/ `permission_key`);
  `users()`; `permissionKeys():array`; `hasPermission($key)`; static `Role::generateId($name)`.
- **User**: `name,username,email,phone,role_id,is_active,must_reset_password`; `role()`; `hasPermission($key)`;
  `initials()`; `roleLabel()`.
- **Setting**: `Setting::current()` → `name,bank,account,iban,next_challan_serial(int),twofa_required(bool)`.
- **Permissions catalog**: `\App\Support\Permissions::grouped()` → `['Group' => [['key'=>..,'label'=>..], ...]]`
  (group order preserved), `::keys()`, `::GROUP_ORDER`, `::label($key)`.
- **Ledger** (`app(\App\Services\Ledger::class)`): `revenueByCourse($user):array[{title,code,amount}]`,
  `billed/received/outstanding/receivedPct/overdueChallans($user)`.

## Validators (spec §9.11)
- CNIC regex: `^\d{5}-\d{7}-\d$`. Phone: strip non-digits, drop leading `92`/`0`, must be 10 digits starting `3`,
  display normalised `+92 3XX XXXXXXX`.

## Scoping (spec §6)
- Officers lack `scope.all` and `revenue.view`. Gate every money total behind `->can('revenue.view')`.
  Use the `visibleTo($user)` scopes for lists. Course/Staff/Settings/Reports/DataModel screens are admin-only
  (route already enforces), so no per-row scoping needed there.
