<script setup lang="ts">
import { Head, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
export interface Seo {
    title: string;
    description: string;
    canonical: string;
    robots: string;
    image: string | null;
    schema: string;
    breadcrumbs?: { name: string; url: string }[];
}
const page = usePage();
const seo = computed(() => page.props.seo as Seo | undefined);
</script>
<template>
    <Head v-if="seo" :title="seo.title"
        ><meta
            head-key="description"
            name="description"
            :content="seo.description"
        /><meta head-key="robots" name="robots" :content="seo.robots" /><link
            head-key="canonical"
            rel="canonical"
            :href="seo.canonical"
        /><meta
            head-key="og:title"
            property="og:title"
            :content="seo.title + ' - Second Hand'"
        /><meta
            head-key="og:description"
            property="og:description"
            :content="seo.description"
        /><meta
            head-key="og:url"
            property="og:url"
            :content="seo.canonical"
        /><meta head-key="og:type" property="og:type" content="website" /><meta
            head-key="og:site_name"
            property="og:site_name"
            content="Second Hand"
        /><meta
            v-if="seo.image"
            head-key="og:image"
            property="og:image"
            :content="seo.image"
        /><meta
            head-key="twitter:card"
            name="twitter:card"
            :content="seo.image ? 'summary_large_image' : 'summary'"
        /><meta
            head-key="twitter:title"
            name="twitter:title"
            :content="seo.title"
        /><meta
            head-key="twitter:description"
            name="twitter:description"
            :content="seo.description"
        /><meta
            v-if="seo.image"
            head-key="twitter:image"
            name="twitter:image"
            :content="seo.image"
        /><component
            :is="'script'"
            v-if="seo.schema !== '[]'"
            head-key="schema"
            type="application/ld+json"
            >{{ seo.schema }}</component
        ></Head
    >
</template>
