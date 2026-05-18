import { useCallback, useEffect, useState } from 'react';
import { Pressable, RefreshControl, ScrollView, Text, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import { LinearGradient } from 'expo-linear-gradient';
import { router, useFocusEffect } from 'expo-router';
import { Bell, Calendar, Sparkles, Trophy, Wine } from 'lucide-react-native';
import Svg, { Circle } from 'react-native-svg';

import { AppBooking, FeedItem, PendingResult, bookings, feed, matchResults } from '@/lib/api';
import { useAuthStore } from '@/lib/auth-store';
import { dateRelative, firstName, greeting, timeUntil } from '@/lib/format';
import { Avatar } from '@/components/Avatar';

export default function Home() {
  const user = useAuthStore((s) => s.user);
  const [nextBooking, setNextBooking] = useState<AppBooking | null>(null);
  const [pending, setPending] = useState<PendingResult[]>([]);
  const [feedItems, setFeedItems] = useState<FeedItem[]>([]);
  const [refreshing, setRefreshing] = useState(false);

  const load = useCallback(async () => {
    try {
      const [b, p, f] = await Promise.all([
        bookings.next(),
        matchResults.pending(),
        feed.get().catch(() => []),
      ]);
      setNextBooking(b);
      setPending(p);
      setFeedItems(f);
    } catch {
      // silent
    }
  }, []);

  useEffect(() => { void load(); }, [load]);
  useFocusEffect(useCallback(() => { void load(); }, [load]));

  const onRefresh = async () => {
    setRefreshing(true);
    await load();
    setRefreshing(false);
  };

  return (
    <SafeAreaView className="flex-1 bg-cream-light dark:bg-dark-bg" edges={['top']}>
      <ScrollView
        contentContainerStyle={{ paddingBottom: 40 }}
        refreshControl={<RefreshControl refreshing={refreshing} onRefresh={onRefresh} tintColor="#6B8068" />}
      >
        {/* Header */}
        <View className="flex-row justify-between items-center px-6 pt-3 pb-1">
          <Text className="font-display-semi text-[17px] tracking-wider text-ink dark:text-cream">
            Le Cercle
          </Text>
          <View className="flex-row items-center gap-2">
            <Pressable className="w-10 h-10 rounded-full items-center justify-center">
              <Bell size={22} color="#1F2419" strokeWidth={1.5} />
            </Pressable>
            <Avatar url={user?.avatar_url} name={user?.name} size={38} />
          </View>
        </View>

        {/* Greeting */}
        <View className="px-6 pt-2 pb-6">
          <View className="flex-row items-baseline gap-2">
            <Text className="text-[18px]">☼</Text>
            <Text className="font-body-medium text-[13px] text-ink-muted">{greeting()},</Text>
          </View>
          <Text className="font-script text-[60px] -mt-1 text-sage-dark dark:text-sage leading-[58px]">
            {firstName(user?.name)}
          </Text>
        </View>

        {/* Pending results banner */}
        {pending.length > 0 && (
          <View className="px-5 mb-3">
            <Pressable
              onPress={() => router.push({
                pathname: `/match-result/${pending[0].booking_id}`,
                params: { opponent: pending[0].opponent?.name ?? pending[0].opponent_name_text ?? '' },
              })}
              className="bg-ocra rounded-2xl p-3 flex-row items-center gap-3"
              style={{ shadowColor: '#C89B5A', shadowOpacity: 0.28, shadowRadius: 12, shadowOffset: { width: 0, height: 4 } }}
            >
              <View className="w-9 h-9 rounded-full bg-white/25 items-center justify-center">
                <Trophy size={18} color="#fff" strokeWidth={1.5} />
              </View>
              <View className="flex-1">
                <Text className="font-display-semi text-[14px] text-white">
                  {pending.length === 1 ? "Hai un risultato da registrare" : `${pending.length} risultati da registrare`}
                </Text>
                <Text className="font-body-medium text-[11px] text-white/85" numberOfLines={1}>
                  vs {pending[0].opponent?.name ?? pending[0].opponent_name_text ?? 'avversario'} · tocca per inserire
                </Text>
              </View>
              <Text className="font-display-semi text-[18px] text-white">›</Text>
            </Pressable>
          </View>
        )}

        {/* Hero — prossima partita */}
        <View className="px-5 mb-5">
          {nextBooking ? (
            <NextBookingCard b={nextBooking} />
          ) : (
            <EmptyNextCard onTap={() => router.push('/booking/new')} />
          )}
        </View>

        {/* Quick actions */}
        <View className="px-6">
          <Text className="font-display-italic text-[19px] text-ink dark:text-cream mb-3">
            Cosa facciamo oggi?
          </Text>
          <View className="flex-row flex-wrap gap-3">
            <QuickAction
              icon={<Calendar size={22} color="#4F6450" strokeWidth={1.5} />}
              label="Prenota campo"
              sub="Calendario"
              onPress={() => router.push('/booking/new')}
              tone="sage"
            />
            <QuickAction
              icon={<Sparkles size={22} color="#A47A3F" strokeWidth={1.5} />}
              label="Trova match"
              sub="Avversario simile"
              onPress={() => router.push('/booking/new?mode=matchmaking')}
              tone="ocra"
            />
            <QuickAction
              icon={<Trophy size={22} color="#4F6450" strokeWidth={1.5} />}
              label="Tornei"
              sub="In arrivo"
              onPress={() => {}}
              tone="sage"
            />
            <QuickAction
              icon={<Wine size={22} color="#A47A3F" strokeWidth={1.5} />}
              label="Eventi"
              sub="In arrivo"
              onPress={() => {}}
              tone="ocra"
            />
          </View>
        </View>

        {/* Feed dal club */}
        <View className="px-6 mt-7">
          <Text className="font-display-italic text-[19px] text-ink dark:text-cream mb-3">
            Dal circolo
          </Text>
          {feedItems.length === 0 ? (
            <View className="bg-white dark:bg-dark-surface rounded-2xl p-4">
              <Text className="text-[13px] text-ink-muted font-body-medium leading-relaxed">
                Le partite e le nuove prenotazioni del circolo appariranno qui.
              </Text>
            </View>
          ) : (
            <View className="flex-col gap-3">
              {feedItems.slice(0, 5).map((item, i) => <FeedRow key={i} item={item} />)}
            </View>
          )}
        </View>
      </ScrollView>
    </SafeAreaView>
  );
}

// ── Components ────────────────────────────────────────────────────────

function NextBookingCard({ b }: { b: AppBooking }) {
  const startsAt = new Date(b.starts_at_iso);
  const time = timeUntil(startsAt);
  const opponentName = b.opponent?.name ?? b.opponent_name_text ?? 'Avversario libero';

  return (
    <Pressable onPress={() => router.push(`/booking/${b.id}`)}>
      <LinearGradient
        colors={['#6B8068', '#8AA086']}
        start={{ x: 0, y: 0 }}
        end={{ x: 1, y: 1 }}
        style={{
          borderRadius: 32,
          padding: 22,
          shadowColor: '#6B8068',
          shadowOpacity: 0.32,
          shadowRadius: 28,
          shadowOffset: { width: 0, height: 14 },
        }}
      >
        {/* Decorative circles */}
        <View style={{ position: 'absolute', right: -50, top: -50, opacity: 0.18 }}>
          <Svg width={200} height={200} viewBox="0 0 200 200">
            <Circle cx="100" cy="100" r="85" stroke="#ECE3CE" strokeWidth="2" fill="none" />
            <Circle cx="100" cy="100" r="55" stroke="#ECE3CE" strokeWidth="1.5" fill="none" />
            <Circle cx="100" cy="100" r="25" stroke="#ECE3CE" strokeWidth="1" fill="none" />
          </Svg>
        </View>

        <View className="flex-row items-center gap-2 mb-1">
          <View className="bg-ocra px-2.5 py-1 rounded-full">
            <Text className="font-body-bold text-[10px] text-white tracking-wide">
              {dateRelative(startsAt).toUpperCase()}
            </Text>
          </View>
          {time && (
            <Text className="font-body-semi text-[11px] text-cream/90">{time}</Text>
          )}
        </View>

        <Text className="font-display text-[32px] text-cream leading-[36px] mt-1">
          Giochi alle <Text className="italic">{b.start_time}</Text>
        </Text>
        <Text className="font-body-medium text-[13px] text-cream/85 mt-1.5">
          Singolo · {b.duration_minutes} min
        </Text>

        <View className="mt-5 pt-4 border-t border-cream/20 flex-row items-center gap-3">
          <Avatar url={b.opponent?.avatar_url} name={opponentName} size={42} bordered />
          <View className="flex-1">
            <Text className="font-display-semi text-[17px] text-cream">{opponentName}</Text>
            {b.opponent?.elo_rating != null && (
              <Text className="font-body-medium text-[11px] text-cream/85 mt-0.5">
                ELO {b.opponent.elo_rating}
              </Text>
            )}
          </View>
          <View className="bg-cream rounded-full px-4 py-2.5">
            <Text className="font-body-bold text-[12px] text-sage-dark">Dettagli ›</Text>
          </View>
        </View>
      </LinearGradient>
    </Pressable>
  );
}

function EmptyNextCard({ onTap }: { onTap: () => void }) {
  return (
    <Pressable onPress={onTap}>
      <View
        className="bg-white dark:bg-dark-surface rounded-[32px] p-6 items-center"
        style={{
          shadowColor: '#6B8068',
          shadowOpacity: 0.1,
          shadowRadius: 20,
          shadowOffset: { width: 0, height: 6 },
        }}
      >
        <View className="w-14 h-14 rounded-full bg-cream items-center justify-center mb-3">
          <Calendar size={26} color="#6B8068" strokeWidth={1.5} />
        </View>
        <Text className="font-display-italic text-[22px] text-ink dark:text-cream text-center">
          Nessuna partita in vista
        </Text>
        <Text className="font-body-medium text-[13px] text-ink-muted text-center mt-1.5 leading-relaxed">
          Prenota un campo o trova un avversario{'\n'}per la tua prossima partita.
        </Text>
        <View className="mt-4 bg-sage px-5 py-2.5 rounded-full">
          <Text className="font-body-bold text-[13px] text-cream">Prenota →</Text>
        </View>
      </View>
    </Pressable>
  );
}

function FeedRow({ item }: { item: FeedItem }) {
  const when = relativeTimeShort(item.happened_at);

  if (item.type === 'match_won') {
    const winner = item.winner;
    const loser = item.loser;
    return (
      <Pressable
        onPress={() => winner && router.push(`/player/${winner.id}`)}
        className="bg-white dark:bg-dark-surface rounded-2xl p-3 flex-row items-center gap-3"
        style={{ shadowColor: '#6B8068', shadowOpacity: 0.06, shadowRadius: 8, shadowOffset: { width: 0, height: 2 } }}
      >
        <Avatar url={item.avatar_url} name={winner?.name} size={36} />
        <View className="flex-1">
          <Text className="text-[13px] text-ink dark:text-cream" numberOfLines={2}>
            <Text className="font-display-semi">{winner?.name ?? 'Qualcuno'}</Text>
            {' ha vinto contro '}
            <Text className="font-display-semi">{loser?.name ?? 'avversario'}</Text>
          </Text>
          <Text className="text-[11px] text-ink-muted mt-0.5 font-body-medium">
            {item.score && <Text className="font-script text-[16px] text-sage-dark">{item.score}</Text>}
            {item.score ? ' · ' : ''}{when}
          </Text>
        </View>
      </Pressable>
    );
  }

  if (item.type === 'booking_created') {
    const player = item.player;
    return (
      <Pressable
        onPress={() => player && router.push(`/player/${player.id}`)}
        className="bg-white dark:bg-dark-surface rounded-2xl p-3 flex-row items-center gap-3"
        style={{ shadowColor: '#6B8068', shadowOpacity: 0.06, shadowRadius: 8, shadowOffset: { width: 0, height: 2 } }}
      >
        <Avatar url={item.avatar_url} name={player?.name} size={36} />
        <View className="flex-1">
          <Text className="text-[13px] text-ink dark:text-cream" numberOfLines={2}>
            <Text className="font-display-semi">{player?.name ?? 'Qualcuno'}</Text>
            {' ha prenotato'}
            {item.opponent_name ? <> con <Text className="font-display-semi">{item.opponent_name}</Text></> : ''}
          </Text>
          <Text className="text-[11px] text-ink-muted mt-0.5 font-body-medium">
            {item.date} alle {item.start_time} · {when}
          </Text>
        </View>
      </Pressable>
    );
  }

  return null;
}

function relativeTimeShort(iso: string): string {
  const ms = Date.now() - new Date(iso).getTime();
  if (ms < 60_000) return 'ora';
  const min = Math.floor(ms / 60_000);
  if (min < 60) return `${min}m fa`;
  const h = Math.floor(min / 60);
  if (h < 24) return `${h}h fa`;
  const d = Math.floor(h / 24);
  return `${d}g fa`;
}

function QuickAction({
  icon, label, sub, onPress, tone,
}: {
  icon: React.ReactNode;
  label: string;
  sub: string;
  onPress: () => void;
  tone: 'sage' | 'ocra';
}) {
  const bg = tone === 'sage' ? 'bg-sage/10' : 'bg-ocra/15';

  return (
    <Pressable
      onPress={onPress}
      className="bg-white dark:bg-dark-surface rounded-3xl p-4"
      style={{
        flexBasis: '47.5%',
        flexGrow: 1,
        shadowColor: '#6B8068',
        shadowOpacity: 0.08,
        shadowRadius: 16,
        shadowOffset: { width: 0, height: 4 },
      }}
    >
      <View className={`w-11 h-11 rounded-full items-center justify-center mb-3 ${bg}`}>
        {icon}
      </View>
      <Text className="font-display-semi text-[15px] text-ink dark:text-cream">{label}</Text>
      <Text className="font-body-medium text-[11px] text-ink-muted mt-0.5">{sub}</Text>
    </Pressable>
  );
}
