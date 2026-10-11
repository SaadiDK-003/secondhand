<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import {
    ArrowUpRight,
    Heart,
    MessageCircle,
    Plus,
    Search,
    ChevronDown,
    UserRound,
} from '@lucide/vue';
import SeoHead from '@/components/SeoHead.vue';
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import UserMenuContent from '@/components/UserMenuContent.vue';
import { Toaster } from '@/components/ui/sonner';
import { useMessageInbox } from '@/composables/useMessageInbox';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
const page = usePage();
const inbox = useMessageInbox();
</script>
<template>
    <SeoHead />
    <div class="min-h-screen bg-background text-foreground">
        <a href="#main-content" class="skip-link">Skip to content</a>
        <header class="border-b bg-card">
            <div
                class="mx-auto flex max-w-7xl flex-wrap items-center justify-between gap-2 px-4 py-3 min-[380px]:gap-3 sm:gap-4 sm:px-5 sm:py-5 lg:px-8"
            >
                <Link
                    href="/"
                    aria-label="Second Hand home"
                    class="flex shrink-0 items-center gap-2 text-base font-extrabold tracking-tight text-primary min-[380px]:text-xl sm:text-2xl"
                    ><span
                        class="flex size-8 items-center justify-center rounded-xl bg-primary text-white sm:size-9"
                        ><AppLogoIcon class="size-6" /></span
                    >2nd<span class="-ml-1 text-foreground"
                        >Hand<span class="text-primary">.</span></span
                    ></Link
                >
                <form
                    action="/search"
                    class="order-3 flex w-full min-w-0 items-center rounded-xl border bg-background px-3 focus-within:ring-2 focus-within:ring-primary lg:order-none lg:w-auto lg:max-w-md lg:flex-1"
                >
                    <Search
                        class="size-4 shrink-0 text-muted-foreground"
                        aria-hidden="true"
                    /><label for="header-search" class="sr-only"
                        >Search items</label
                    ><input
                        id="header-search"
                        name="q"
                        type="search"
                        maxlength="120"
                        placeholder="Find your next good thing"
                        class="min-w-0 flex-1 bg-transparent px-3 py-2.5 text-sm outline-none"
                    /><button
                        aria-label="Submit search"
                        class="inline-flex min-w-11 items-center justify-center rounded-md p-1.5 text-primary hover:bg-accent sm:min-w-0"
                    >
                        <ArrowUpRight class="size-4" />
                    </button>
                </form>
                <nav
                    aria-label="Account"
                    class="flex items-center gap-1 text-sm font-semibold min-[380px]:gap-2 sm:gap-4"
                >
                    <Link
                        v-if="page.props.auth.user"
                        href="/messages"
                        :aria-label="
                            inbox?.unread_count
                                ? `Messages, ${inbox.unread_count} unread`
                                : 'Messages'
                        "
                        class="relative inline-flex min-h-11 min-w-11 items-center justify-center rounded-lg hover:bg-accent hover:text-primary"
                    >
                        <MessageCircle class="size-5" aria-hidden="true" />
                        <span
                            v-if="inbox?.unread_count"
                            aria-hidden="true"
                            class="absolute top-0 right-0 flex min-h-5 min-w-5 items-center justify-center rounded-full bg-primary px-1 text-[10px] font-bold text-white"
                            >{{
                                inbox.unread_count > 99
                                    ? '99+'
                                    : inbox.unread_count
                            }}</span
                        >
                    </Link>
                    <Link
                        :href="page.props.auth.user ? '/my-listings' : '/login'"
                        :aria-label="
                            page.props.auth.user ? 'My listings' : 'Log in'
                        "
                        :class="
                            page.props.auth.user
                                ? 'hidden sm:inline-flex'
                                : 'inline-flex min-h-11 min-w-11 items-center justify-center'
                        "
                        class="hover:text-primary"
                        ><template v-if="page.props.auth.user"
                            >My listings</template
                        ><template v-else
                            ><UserRound
                                class="size-5 sm:hidden"
                                aria-hidden="true"
                            /><span class="hidden sm:inline"
                                >Log in</span
                            ></template
                        ></Link
                    >
                    <DropdownMenu v-if="page.props.auth.user">
                        <DropdownMenuTrigger as-child>
                            <button
                                type="button"
                                class="inline-flex min-h-11 min-w-11 items-center justify-center gap-1 rounded-lg hover:text-primary"
                                aria-label="Account menu"
                            >
                                <UserRound
                                    class="size-5 sm:hidden"
                                    aria-hidden="true"
                                /><span class="hidden sm:inline">Account</span
                                ><ChevronDown
                                    class="hidden size-4 sm:inline"
                                    aria-hidden="true"
                                />
                            </button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end" class="w-56">
                            <UserMenuContent :user="page.props.auth.user" />
                        </DropdownMenuContent>
                    </DropdownMenu>
                    <Link
                        href="/sell"
                        aria-label="Sell an item"
                        class="inline-flex min-h-11 min-w-11 items-center justify-center gap-2 rounded-xl bg-primary px-2 py-3 text-white shadow-sm hover:bg-[#0052A3] min-[380px]:px-3 sm:px-4"
                        ><Plus class="size-4" aria-hidden="true" /><span
                            class="hidden min-[380px]:inline sm:hidden"
                            >Sell</span
                        ><span class="hidden sm:inline"
                            >Sell an item</span
                        ></Link
                    >
                </nav>
            </div>
            <nav
                aria-label="Browse"
                class="browse-nav mx-auto flex max-w-7xl items-center gap-5 overflow-x-auto px-4 pb-2 text-sm whitespace-nowrap sm:gap-6 sm:px-5 sm:pb-4 lg:px-8"
            >
                <Link href="/search" class="font-semibold hover:text-primary"
                    >Explore all</Link
                ><Link href="/category/furniture" class="hover:text-primary"
                    >Furniture</Link
                ><Link
                    href="/category/mobiles-tablets"
                    class="hover:text-primary"
                    >Mobiles</Link
                ><Link href="/category/electronics" class="hover:text-primary"
                    >Electronics</Link
                ><Link href="/category/fashion" class="hover:text-primary"
                    >Fashion</Link
                ><Link
                    href="/favorites"
                    class="ml-auto inline-flex items-center gap-2 hover:text-primary"
                    ><Heart class="size-4" />Saved</Link
                ><Link
                    href="/messages"
                    class="inline-flex items-center gap-2 hover:text-primary"
                    ><MessageCircle class="size-4" />Messages
                    <span
                        v-if="inbox?.unread_count"
                        class="relative rounded-full bg-primary px-1.5 py-0.5 text-xs font-semibold text-white"
                        >{{
                            inbox.unread_count > 99
                                ? '99+'
                                : inbox.unread_count
                        }}<span class="sr-only"> unread messages</span></span
                    ></Link
                >
            </nav>
        </header>
        <div id="main-content" tabindex="-1"><slot /></div>
        <footer class="mt-12 border-t bg-card">
            <div
                class="mx-auto flex max-w-7xl flex-wrap items-center justify-between gap-6 px-5 py-8 text-sm lg:px-8"
            >
                <div>
                    <Link href="/" class="text-lg font-bold text-primary"
                        >2nd Hand.</Link
                    >
                    <p class="mt-2 text-muted-foreground">
                        Good things deserve a second home.
                    </p>
                </div>
                <div class="flex flex-wrap gap-x-5 gap-y-2">
                    <Link href="/search" class="hover:text-primary"
                        >Explore items</Link
                    ><Link href="/sell" class="hover:text-primary"
                        >Start selling</Link
                    ><Link href="/settings/profile" class="hover:text-primary"
                        >Your account</Link
                    >
                </div>
                <p class="text-xs text-muted-foreground">
                    Made for second chances. Made for Pakistan.
                </p>
            </div>
        </footer>
        <Toaster position="top-right" close-button :mobile-offset="24" />
    </div>
</template>
