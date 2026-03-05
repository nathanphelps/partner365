<?php

namespace App\Http\Controllers;

use App\Enums\ActivityAction;
use App\Http\Requests\StoreTemplateRequest;
use App\Models\PartnerTemplate;
use App\Services\ActivityLogService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class PartnerTemplateController extends Controller
{
    public function __construct(private ActivityLogService $activityLog) {}

    public function index(): Response
    {
        return Inertia::render('templates/Index', [
            'templates' => PartnerTemplate::with('createdBy')->orderBy('name')->paginate(25),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('templates/Create');
    }

    public function store(StoreTemplateRequest $request): RedirectResponse
    {
        $template = PartnerTemplate::create([
            ...$request->validated(),
            'created_by_user_id' => $request->user()->id,
        ]);

        $this->activityLog->log($request->user(), ActivityAction::TemplateCreated, $template, [
            'name' => $template->name,
        ]);

        return redirect()->route('templates.index')->with('success', "Template '{$template->name}' created.");
    }

    public function edit(PartnerTemplate $template): Response
    {
        return Inertia::render('templates/Edit', [
            'template' => $template,
        ]);
    }

    public function update(StoreTemplateRequest $request, PartnerTemplate $template): RedirectResponse
    {
        $template->update($request->validated());

        $this->activityLog->log($request->user(), ActivityAction::TemplateUpdated, $template, [
            'name' => $template->name,
        ]);

        return redirect()->route('templates.index')->with('success', "Template '{$template->name}' updated.");
    }

    public function destroy(PartnerTemplate $template): RedirectResponse
    {
        $name = $template->name;
        $this->activityLog->log(auth()->user(), ActivityAction::TemplateDeleted, $template, [
            'name' => $name,
        ]);

        $template->delete();

        return redirect()->route('templates.index')->with('success', 'Template deleted.');
    }
}
