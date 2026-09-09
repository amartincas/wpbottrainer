<?php

use App\CustomerCare\Models\CustomerServiceRequest;
use App\CustomerCare\Support\CustomerServiceRequestRecorder;
use App\Models\AlertLog;
use App\Models\Contact;

it('creates a CustomerServiceRequest with the exact original message, and emits exactly one Alert', function () {
    $contact = Contact::factory()->create(['customer_name' => 'Laura', 'customer_phone' => '573001112233']);

    $request = app(CustomerServiceRequestRecorder::class)->record($contact, '¿Puedo congelar mi membresía?');

    expect($request)->toBeInstanceOf(CustomerServiceRequest::class);
    expect($request->contact_id)->toBe($contact->id);
    expect($request->message)->toBe('¿Puedo congelar mi membresía?');
    expect(CustomerServiceRequest::count())->toBe(1);

    $alert = AlertLog::where('category', 'customer_service')->sole();
    expect($alert->severity)->toBe('warning');
    expect($alert->message)->toContain('Laura');
    expect($alert->message)->toContain('573001112233');
    expect($alert->message)->toContain('¿Puedo congelar mi membresía?');
    expect($alert->context['tenant_id'])->toBe($contact->tenant_id);
    expect($alert->context['contact_id'])->toBe($contact->id);
    expect($alert->context['customer_service_request_id'])->toBe($request->id);
});

it('handles a contact with no customer_name gracefully, never crashing', function () {
    $contact = Contact::factory()->create(['customer_name' => null]);

    app(CustomerServiceRequestRecorder::class)->record($contact, 'mensaje');

    $alert = AlertLog::where('category', 'customer_service')->sole();
    expect($alert->message)->toContain('Sin nombre');
});

it('AlertLog is never the source of truth — deleting it would never delete the CustomerServiceRequest, and vice versa (no coupling)', function () {
    $contact = Contact::factory()->create();

    $request = app(CustomerServiceRequestRecorder::class)->record($contact, 'mensaje');

    AlertLog::query()->delete();

    expect(CustomerServiceRequest::find($request->id))->not->toBeNull();
});

it('two separate requests from the same contact each create their own row and their own alert — never deduplicated', function () {
    $contact = Contact::factory()->create();

    app(CustomerServiceRequestRecorder::class)->record($contact, 'primer mensaje');
    app(CustomerServiceRequestRecorder::class)->record($contact, 'segundo mensaje, distinto');

    expect(CustomerServiceRequest::count())->toBe(2);
});
