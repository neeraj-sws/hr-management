<x-field label="Issue Date" name="issueDate"><input type="date" wire:model.live="issueDate"></x-field>
<x-field label="Category" name="categoryId"><select wire:model="categoryId"><option value="">Select Category</option>@foreach($categories as $c)<option value="{{ $c->id }}">{{ $c->name }}</option>@endforeach</select></x-field>
<x-field label="Proposal Number" name="number"><input value="{{ $numberPreview }}" readonly aria-readonly="true"></x-field>
<x-field label="Valid till" name="expiryDate"><input type="date" wire:model="expiryDate"></x-field>
