<x-field label="Issue Date" name="issueDate"><input type="date" wire:model.live="issueDate"></x-field>
<x-field label="Due Date" name="dueDate"><input type="date" wire:model="dueDate"></x-field>
<x-field label="Invoice Number" name="number"><input value="{{ $numberPreview }}" readonly aria-readonly="true"></x-field>
<x-field label="Category" name="categoryId"><select wire:model="categoryId"><option value="">Select Category</option>@foreach($categories as $c)<option value="{{ $c->id }}">{{ $c->name }}</option>@endforeach</select></x-field>
<x-field label="Ref Number" name="refNumber"><input wire:model="refNumber" placeholder="Enter Ref Number"></x-field>
<x-field label="Hours" name="hours"><input type="number" step="0.01" min="0" wire:model="hours"></x-field>
