<template>
  <div class="min-h-screen bg-atomic-ink px-5 py-10 sm:py-16">
    <div class="mx-auto w-full max-w-2xl">

      <!-- Brand + step rail -->
      <div class="mb-8 flex items-center justify-between">
        <span class="font-display text-2xl tracking-tight text-atomic-cream">Snapsbrew</span>
        <p class="sr-only" aria-live="polite">Step {{ step }} of 3</p>
        <div class="flex items-center gap-2" aria-hidden="true">
          <span
            v-for="n in 3"
            :key="n"
            class="h-3 w-3 rounded-full border-[3px] border-atomic-cream transition"
            :class="n <= step ? 'bg-atomic-coral' : 'bg-atomic-ink'"
          ></span>
        </div>
      </div>

      <div class="rounded-2xl border-[3px] border-atomic-ink bg-atomic-cream p-7 shadow-[8px_8px_0_0_#f0573f] sm:p-10">

        <!-- Step 1 — profile -->
        <section v-if="step === 1">
          <h1 ref="heading" tabindex="-1" class="font-display text-3xl tracking-tight text-atomic-ink outline-none sm:text-4xl">
            Welcome aboard
          </h1>
          <p class="mt-2 font-sans text-atomic-ink/70">Let's get your counter set up. First, the basics.</p>

          <div class="mt-7 space-y-5">
            <div>
              <label class="font-sans font-semibold text-atomic-ink" for="ob-name">Your name</label>
              <input
                id="ob-name"
                v-model="form.name"
                type="text"
                class="mt-1.5 w-full rounded-full border-[3px] border-atomic-ink bg-white px-5 py-3 font-sans text-atomic-ink focus-visible:outline-solid focus-visible:outline-[3px] focus-visible:outline-offset-2 focus-visible:outline-atomic-ink"
                placeholder="Jane Diner"
                :aria-invalid="errorFor('name') ? 'true' : null"
                :aria-describedby="errorFor('name') ? 'ob-name-error' : null"
              >
              <p v-if="errorFor('name')" id="ob-name-error" class="mt-1 font-sans text-sm text-atomic-coral-dk">{{ errorFor('name') }}</p>
            </div>
            <p v-if="!canRenameCompany" class="font-sans text-atomic-ink/70">You joined {{ initialCompany }}.</p>
            <div v-else>
              <label class="font-sans font-semibold text-atomic-ink" for="ob-company">Company name</label>
              <input
                id="ob-company"
                v-model="form.companyName"
                type="text"
                class="mt-1.5 w-full rounded-full border-[3px] border-atomic-ink bg-white px-5 py-3 font-sans text-atomic-ink focus-visible:outline-solid focus-visible:outline-[3px] focus-visible:outline-offset-2 focus-visible:outline-atomic-ink"
                placeholder="Acme Co."
                :aria-invalid="errorFor('company_name') ? 'true' : null"
                :aria-describedby="errorFor('company_name') ? 'ob-company-error' : null"
              >
              <p v-if="errorFor('company_name')" id="ob-company-error" class="mt-1 font-sans text-sm text-atomic-coral-dk">{{ errorFor('company_name') }}</p>
            </div>
          </div>
        </section>

        <!-- Step 2 — categories -->
        <section v-else-if="step === 2">
          <h1 ref="heading" tabindex="-1" class="font-display text-3xl tracking-tight text-atomic-ink outline-none sm:text-4xl">
            What are you making?
          </h1>
          <p class="mt-2 font-sans text-atomic-ink/70">Pick all that fit — we'll tailor your starting point.</p>

          <div class="mt-7 grid gap-3 sm:grid-cols-2">
            <button
              v-for="cat in categories"
              :key="cat.slug"
              type="button"
              :aria-pressed="form.categories.includes(cat.slug) ? 'true' : 'false'"
              @click="toggleCategory(cat.slug)"
              class="rounded-2xl border-[3px] border-atomic-ink p-4 text-left transition hover:translate-x-[3px] hover:translate-y-[3px] hover:shadow-[3px_3px_0_0_#211d1a]"
              :class="form.categories.includes(cat.slug)
                ? 'bg-atomic-aqua text-atomic-cream shadow-atomic'
                : 'bg-white text-atomic-ink shadow-atomic'"
            >
              <span class="font-display text-lg tracking-tight">{{ cat.label }}</span>
              <span class="mt-1 block font-sans text-sm" :class="form.categories.includes(cat.slug) ? 'text-atomic-cream/85' : 'text-atomic-ink/65'">
                {{ cat.blurb }}
              </span>
            </button>
          </div>
          <p v-if="errorFor('categories')" class="mt-3 font-sans text-sm text-atomic-coral-dk">{{ errorFor('categories') }}</p>
        </section>

        <!-- Step 3, after the invites, when mail is not set up -->
        <section v-else-if="inviteLinks">
          <h1 ref="heading" tabindex="-1" class="font-display text-3xl tracking-tight text-atomic-ink outline-none sm:text-4xl">
            Send the invite links
          </h1>
          <p class="mt-2 font-sans text-atomic-ink/70">Mail is not set up. Send this link yourself.</p>

          <ul class="mt-7 space-y-4">
            <li v-for="invite in inviteLinks" :key="invite.email">
              <p class="font-sans font-semibold text-atomic-ink">{{ invite.email }}</p>
              <div class="mt-1.5 flex items-stretch gap-2">
                <code class="min-w-0 flex-1 rounded-2xl border-[3px] border-atomic-ink bg-white px-4 py-2.5 font-mono text-xs break-all text-atomic-ink">{{ invite.url }}</code>
                <button
                  type="button"
                  @click="copyInvite(invite)"
                  class="shrink-0 rounded-full border-[3px] border-atomic-ink bg-atomic-cream px-4 font-sans text-sm font-bold text-atomic-ink shadow-atomic transition hover:translate-x-[3px] hover:translate-y-[3px] hover:shadow-[3px_3px_0_0_#211d1a] focus-visible:outline-solid focus-visible:outline-[3px] focus-visible:outline-offset-2 focus-visible:outline-atomic-ink"
                  :aria-label="`Copy the invite link of ${invite.email}`"
                >
                  {{ copied === invite.email ? 'Copied' : 'Copy' }}
                </button>
              </div>
            </li>
          </ul>
          <p class="mt-4 font-sans text-sm text-atomic-ink/70">Each link works for 7 days. You can copy the links again on the Account page.</p>
        </section>

        <!-- Step 3 — invites -->
        <section v-else>
          <h1 ref="heading" tabindex="-1" class="font-display text-3xl tracking-tight text-atomic-ink outline-none sm:text-4xl">
            Bring the crew
          </h1>
          <p class="mt-2 font-sans text-atomic-ink/70">Invite teammates to your company — or skip and do it later.</p>

          <div class="mt-7 space-y-3">
            <div v-for="(email, i) in form.emails" :key="i">
              <label :for="`ob-invite-${i}`" class="sr-only">Teammate email {{ i + 1 }}</label>
              <input
                :id="`ob-invite-${i}`"
                v-model="form.emails[i]"
                type="email"
                class="w-full rounded-full border-[3px] border-atomic-ink bg-white px-5 py-3 font-sans text-atomic-ink focus-visible:outline-solid focus-visible:outline-[3px] focus-visible:outline-offset-2 focus-visible:outline-atomic-ink"
                placeholder="teammate@company.com"
                :aria-invalid="emailErrors[i] ? 'true' : null"
                :aria-describedby="emailErrors[i] ? `ob-invite-${i}-error` : null"
              >
              <p v-if="emailErrors[i]" :id="`ob-invite-${i}-error`" class="mt-1 px-5 font-sans text-sm text-atomic-coral-dk">{{ emailErrors[i] }}</p>
            </div>
            <button
              type="button"
              @click="form.emails.push('')"
              class="font-sans font-semibold text-atomic-aqua-dk hover:text-atomic-ink"
            >
              + Add another
            </button>
          </div>
          <p v-if="errorFor('emails')" class="mt-2 font-sans text-sm text-atomic-coral-dk">{{ errorFor('emails') }}</p>
        </section>

        <p v-if="errorFor('general')" class="mt-6 font-sans text-sm font-semibold text-atomic-coral-dk" role="alert">{{ errorFor('general') }}</p>

        <!-- Controls -->
        <div v-if="inviteLinks" class="mt-9 flex justify-end">
          <button
            type="button"
            :disabled="submitting"
            @click="complete"
            class="inline-flex items-center gap-2 rounded-full border-[3px] border-atomic-ink bg-atomic-coral px-7 py-3 font-sans font-bold text-atomic-cream shadow-atomic transition hover:translate-x-[3px] hover:translate-y-[3px] hover:bg-atomic-coral-dk hover:shadow-[3px_3px_0_0_#211d1a] focus-visible:outline-solid focus-visible:outline-[3px] focus-visible:outline-offset-2 focus-visible:outline-atomic-ink disabled:opacity-60"
          >
            Go to the dashboard
          </button>
        </div>
        <div v-else class="mt-9 flex items-center justify-between gap-3">
          <button
            v-if="step > 1"
            type="button"
            :disabled="submitting"
            @click="back"
            class="font-sans font-semibold text-atomic-ink/70 hover:text-atomic-ink disabled:opacity-50"
          >
            &larr; Back
          </button>
          <span v-else></span>

          <div class="flex items-center gap-3">
            <button
              v-if="step === 3"
              type="button"
              :disabled="submitting"
              @click="finish(true)"
              class="font-sans font-semibold text-atomic-ink/70 hover:text-atomic-ink disabled:opacity-50"
            >
              Skip
            </button>
            <button
              type="button"
              :disabled="submitting"
              @click="advance"
              class="inline-flex items-center gap-2 rounded-full border-[3px] border-atomic-ink bg-atomic-coral px-7 py-3 font-sans font-bold text-atomic-cream shadow-atomic transition hover:translate-x-[3px] hover:translate-y-[3px] hover:bg-atomic-coral-dk hover:shadow-[3px_3px_0_0_#211d1a] focus-visible:outline-solid focus-visible:outline-[3px] focus-visible:outline-offset-2 focus-visible:outline-atomic-ink disabled:opacity-60"
            >
              {{ submitting ? 'Just a sec…' : (step === 3 ? 'Finish' : 'Continue') }}
            </button>
          </div>
        </div>

      </div>
    </div>
  </div>
</template>

<script>
import { copyText } from '../copyText'

export default {
  name: 'OnboardingWizard',

  props: {
    initialName: { type: String, default: '' },
    initialCompany: { type: String, default: '' },
    // Only the founder of the company can set its name. An invited member cannot.
    canRenameCompany: { type: Boolean, default: true },
  },

  data() {
    return {
      step: 1,
      submitting: false,
      errors: {},
      // The error of each invite field, by the position of the field.
      emailErrors: {},
      // The invite links to show when the server cannot send mail.
      inviteLinks: null,
      copied: null,
      categories: [
        { slug: 'social', label: 'Social media posts', blurb: 'Instagram, TikTok, LinkedIn — sized and ready.' },
        { slug: 'blog', label: 'Blog & OG images', blurb: 'Link previews and headers that pop.' },
        { slug: 'certificate', label: 'Certificates & badges', blurb: 'Course certs and achievement badges.' },
        { slug: 'marketing', label: 'Marketing & ads', blurb: 'Promo cards and announcement graphics.' },
      ],
      form: {
        name: this.initialName || '',
        companyName: this.initialCompany || '',
        categories: [],
        emails: ['', ''],
      },
    }
  },

  watch: {
    // Move the focus to the heading of the new step, so a screen reader reads it.
    step() {
      this.$nextTick(() => this.$refs.heading?.focus())
    },
    inviteLinks() {
      this.$nextTick(() => this.$refs.heading?.focus())
    },
  },

  methods: {
    // A 422 carries field errors. Any other failure (429, 500, no network)
    // carries none, so the wizard shows one general message.
    errorsFrom(e) {
      const errors = e.response?.data?.errors
      if (errors) return errors
      const message = e.response?.status === 429
        ? 'Too many attempts. Wait one minute, then try again.'
        : 'We could not save this step. Try again.'
      return { general: [message] }
    },

    errorFor(field) {
      const list = this.errors[field]
      return Array.isArray(list) ? list[0] : null
    },

    toggleCategory(slug) {
      const i = this.form.categories.indexOf(slug)
      if (i === -1) {
        this.form.categories.push(slug)
      } else {
        this.form.categories.splice(i, 1)
      }
    },

    back() {
      this.errors = {}
      this.emailErrors = {}
      if (this.step > 1) this.step -= 1
    },

    async advance() {
      if (this.step === 1) {
        const profile = { name: this.form.name }
        if (this.canRenameCompany) profile.company_name = this.form.companyName
        await this.submitStep('/onboarding/profile', profile, () => { this.step = 2 })
      } else if (this.step === 2) {
        await this.submitStep('/onboarding/categories', {
          categories: this.form.categories,
        }, () => { this.step = 3 })
      } else {
        await this.finish(false)
      }
    },

    async finish(skipInvites) {
      if (this.submitting) return
      this.submitting = true
      this.errors = {}
      this.emailErrors = {}

      // The server reads only the filled fields, so `emails.0` can be the
      // second field. Keep the position of each sent address.
      const sent = this.form.emails
        .map((value, index) => ({ value: value.trim(), index }))
        .filter(item => item.value)

      try {
        if (!skipInvites && sent.length) {
          const { data: invited } = await window.axios.post('/onboarding/invites', { emails: sent.map(item => item.value) })
          if (invited?.mail_configured === false && invited.invitations?.length) {
            this.inviteLinks = invited.invitations
            this.submitting = false
            return
          }
        }
        await this.redirectToDashboard()
      } catch (e) {
        const errors = this.errorsFrom(e)
        Object.keys(errors).forEach(key => {
          const match = key.match(/^emails\.(\d+)$/)
          if (match && sent[match[1]]) {
            this.emailErrors[sent[match[1]].index] = 'Enter a valid email address, or clear this field.'
          }
        })
        if (Object.keys(this.emailErrors).length) {
          this.errors = { general: ['An email address is not valid. Correct it or clear the field.'] }
        } else {
          this.errors = errors.emails ? { general: errors.emails } : errors
        }
        this.submitting = false
      }
    },

    async redirectToDashboard() {
      const { data } = await window.axios.post('/onboarding/finish', {})
      window.location.assign(data.redirect || '/dashboard')
    },

    async complete() {
      if (this.submitting) return
      this.submitting = true
      this.errors = {}
      try {
        await this.redirectToDashboard()
      } catch (e) {
        this.errors = this.errorsFrom(e)
        this.submitting = false
      }
    },

    async copyInvite(invite) {
      await copyText(invite.url)
      this.copied = invite.email
      window.setTimeout(() => { if (this.copied === invite.email) this.copied = null }, 2000)
    },

    async submitStep(url, payload, onSuccess) {
      if (this.submitting) return
      this.submitting = true
      this.errors = {}

      try {
        await window.axios.post(url, payload)
        onSuccess()
      } catch (e) {
        this.errors = this.errorsFrom(e)
      } finally {
        this.submitting = false
      }
    },
  },
}
</script>
