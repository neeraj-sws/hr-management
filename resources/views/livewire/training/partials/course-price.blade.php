{{-- A course's two prices: in installments (full fee) and at once (less the one-time discount). $course = TrainingCourse with a fee. --}}
<span class="tc-price">{{ number_format($course->installmentFee(), 2) }}<small>in installments{{ $course->default_installments ? ' ('.$course->default_installments.')' : '' }}</small></span>
@if((float) $course->one_time_discount > 0)<span class="tc-price">{{ number_format($course->atOnceFee(), 2) }}<small>at once</small></span>@endif
