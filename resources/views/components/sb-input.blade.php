{{--
  Kept for App\View\Components\SbInput. New code uses <x-ui.input>.
  The old view always printed `required`, so this wrapper keeps the field required.
--}}
<x-ui.input :type="$type" :name="$name" :id="$id" :placeholder="$placeholder" :value="$value" required />
