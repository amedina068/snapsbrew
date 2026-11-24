<template>
    <div>
        <slot :copyLink="copyLink"></slot>
        <div class="absolute top-0 -mt-6 text-sm text-rose-700 font-medium right-0" v-show="linkCopied">Copied!</div>
    </div>
</template>

<script setup>
    import { ref, nextTick } from 'vue'

    const props = defineProps({
        link: {
            required: true,
            type: String
        }
    })

    const linkCopied = ref(false)

    const copyLink = () => {
        linkCopied.value = true
        const inputWithLink = document.body.appendChild(document.createElement("input"))
        inputWithLink.value = props.link
        inputWithLink.style.opacity = 0
        inputWithLink.style.position = 'absolute'
        inputWithLink.style.top = 0
        inputWithLink.focus()
        inputWithLink.select()
        document.execCommand('copy')
        inputWithLink.parentNode.removeChild(inputWithLink)
        nextTick(() => window.setTimeout(() => linkCopied.value = false, 1000))  
    }
</script>