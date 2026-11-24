<template>
  <div class="flex justify-between items-center">
    <div class="flex space-x-2">
      <div>
      </div>
      <div>
        <p class="text-gray-900 font-semibold">{{ userData.name }}</p>
        <p class="text-sm text-gray-500">{{ userData.email }}</p>  
      </div>
    </div>
    <LogOutForm/>
  </div>
</template>

<script>
  import { toRefs } from 'vue'
  import LogOutForm from './LogOutForm'
  export default {
    props: {
      userData: {
        type: Object,
        required: true,
      }
    },
    components: {
      LogOutForm
    },
    setup(props) {
      const { userData } = toRefs(props)

      return { userData }
    }
  }
</script>