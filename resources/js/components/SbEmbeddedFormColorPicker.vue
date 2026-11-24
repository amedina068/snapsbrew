<template>
    <div class="flex items-center space-x-2">
        <ScColorPicker
            :value="value"
            @change="updateInput"
        />
        <div class="max-w-md w-full">
            <SbEmbeddedFormInput
                type="hidden"
                :id="id"
                :value="inputValue"
            />    
        </div>
        
    </div>
</template>

<script setup>
    import { ref, toRefs } from 'vue'
    import SbEmbeddedFormInput from './SbEmbeddedFormInput'
    import ScColorPicker from './ScColorPicker'

    const props = defineProps({
        id: {
            type: String,
            required: true
        },
        value: {
            type: String,
            required: true
        }
    })

    const { value } =  toRefs(props)

    const inputValue = ref(value.value)

    const updateInput = event => inputValue.value = event
</script>