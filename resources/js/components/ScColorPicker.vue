<template>
    <div class="w-full">
      <span :id="id"></span>
    </div>
</template>

<script setup>
  import { toRefs, watch } from 'vue'
  import { onMounted } from 'vue'
  import Pickr from '@simonwep/pickr'
  import '@simonwep/pickr/dist/themes/nano.min.css'

  const props = defineProps({
    value: {
      type: String,
      required: true,
    }
  })
  const emit = defineEmits(['change'])

  const id = `picker-${parseInt(Math.random() * 1000)}`
  const { value } =  toRefs(props)

  let pickr = null

  watch(value, newValue => {
    pickr.setColor(newValue, true)
    pickr.applyColor(true)
  })

  onMounted(() => {
    pickr = Pickr.create({
      el: `#${id}`,
      theme: 'nano',
      default: value.value,
      components: {
        preview: true,
        opacity: false,
        hue: true,
        interaction: {
          rgba: true,
          input: true,
          save: true
        }
      }
    }).on('save',  (color, instance) => {
      emit('change', color.toRGBA().toString())
      instance.hide()
    })

  })

</script>

<style>
  .pickr .pcr-button {
    width: 100%;
    height: 1.5rem;
  }
</style>