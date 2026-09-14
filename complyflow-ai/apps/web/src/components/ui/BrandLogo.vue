<script setup lang="ts">
defineProps<{ inverse?: boolean }>()
</script>

<template>
  <div
    class="brand"
    :class="{ 'brand-inverse': inverse }"
    aria-label="ComplyFlow AI — Conformidade Documental"
  >
    <svg
      viewBox="0 0 48 52"
      width="42"
      height="46"
      fill="none"
      aria-hidden="true"
    >
      <path
        d="M4 10a8 8 0 0 1 8-8h16l12 12v24a8 8 0 0 1-8 8H12a8 8 0 0 1-8-8Z"
        fill="#008378"
      />
      <path
        d="M28 2v8a4 4 0 0 0 4 4h8"
        fill="#00685f"
      />
      <circle
        cx="16"
        cy="18"
        r="2.5"
        fill="white"
      />
      <circle
        cx="28"
        cy="18"
        r="2.5"
        fill="white"
        opacity=".5"
      />
      <path
        d="M16 18h12"
        stroke="white"
        stroke-dasharray="2 2"
        opacity=".5"
      />
      <circle
        cx="25"
        cy="33"
        r="11"
        fill="#0f172a"
      />
      <circle
        cx="25"
        cy="33"
        r="9"
        fill="#008378"
      />
      <path
        d="m21 33 3 3 5-6"
        stroke="white"
        stroke-width="2.2"
        stroke-linecap="round"
        stroke-linejoin="round"
      />
    </svg>
    <span><span class="brand-name">ComplyFlow <b>AI</b></span><span class="brand-caption">Conformidade Documental</span></span>
  </div>
</template>
