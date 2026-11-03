@if(count($templates['files']))

{{ count($templates['files']) === 1 ? 'The branding template is' : 'The branding templates are' }} attached too: {{ count($templates['files']) === 1 ? 'it shows' : 'they show' }} each branding position and its maximum size, so you can set up your artwork to suit.
@endif
@if(count($templates['left_out']))

{{ count($templates['left_out']) === 1 ? 'One more template was' : count($templates['left_out']).' more templates were' }} too large to attach ({{ implode(', ', $templates['left_out']) }}). Just reply and we'll send {{ count($templates['left_out']) === 1 ? 'it' : 'them' }} separately.
@endif
