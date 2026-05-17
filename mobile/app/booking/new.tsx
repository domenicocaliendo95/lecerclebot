import { useCallback, useEffect, useState } from 'react';
import { ActivityIndicator, Alert, Keyboard, Pressable, ScrollView, Text, TextInput, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import { router, useLocalSearchParams } from 'expo-router';
import { LinearGradient } from 'expo-linear-gradient';
import { Check, ChevronLeft, Search, X } from 'lucide-react-native';

import {
  AvailabilitySlot,
  BookingType,
  PlayerSearchResult,
  bookings,
  players,
} from '@/lib/api';
import { dateRelative, money } from '@/lib/format';
import { Avatar } from '@/components/Avatar';

type Duration = 60 | 90 | 120;
const DURATION_LABELS: Record<Duration, string> = { 60: '1 ora', 90: '1h 30', 120: '2 ore' };

export default function NewBooking() {
  const params = useLocalSearchParams<{ mode?: string }>();
  const initialType: BookingType = params.mode === 'matchmaking' ? 'matchmaking' : 'con_avversario';

  const [dates] = useState(() => buildDateStrip(14));
  const [selectedDate, setSelectedDate] = useState<string>(dates[0].iso);
  const [duration, setDuration] = useState<Duration>(60);
  const [slots, setSlots] = useState<AvailabilitySlot[]>([]);
  const [loadingSlots, setLoadingSlots] = useState(false);
  const [selectedSlot, setSelectedSlot] = useState<AvailabilitySlot | null>(null);

  const [type, setType] = useState<BookingType>(initialType);
  const [opponentQuery, setOpponentQuery] = useState('');
  const [opponentResults, setOpponentResults] = useState<PlayerSearchResult[]>([]);
  const [opponent, setOpponent] = useState<PlayerSearchResult | null>(null);
  const [opponentFreeText, setOpponentFreeText] = useState('');

  const [notes, setNotes] = useState('');
  const [submitting, setSubmitting] = useState(false);

  // Load slots whenever date or duration changes
  const loadSlots = useCallback(async () => {
    setLoadingSlots(true);
    setSelectedSlot(null);
    try {
      const res = await bookings.availability(selectedDate, duration);
      setSlots(res.slots);
    } catch {
      setSlots([]);
    } finally {
      setLoadingSlots(false);
    }
  }, [selectedDate, duration]);

  useEffect(() => { void loadSlots(); }, [loadSlots]);

  // Debounced opponent search
  useEffect(() => {
    if (type !== 'con_avversario' || opponent || opponentQuery.trim().length < 2) {
      setOpponentResults([]);
      return;
    }
    const handle = setTimeout(async () => {
      try {
        const results = await players.search(opponentQuery.trim());
        setOpponentResults(results);
      } catch {
        setOpponentResults([]);
      }
    }, 300);
    return () => clearTimeout(handle);
  }, [opponentQuery, opponent, type]);

  const canSubmit =
    selectedSlot &&
    !submitting &&
    (type !== 'con_avversario' || opponent || opponentFreeText.trim().length > 0);

  const submit = async () => {
    if (!canSubmit || !selectedSlot) return;

    setSubmitting(true);
    try {
      const created = await bookings.create({
        date: selectedDate,
        start_time: selectedSlot.time,
        duration_minutes: duration,
        type,
        opponent_user_id: type === 'con_avversario' && opponent ? opponent.id : null,
        opponent_name_text:
          type === 'con_avversario' && !opponent && opponentFreeText.trim()
            ? opponentFreeText.trim()
            : null,
        payment_method: 'in_loco',
        notes: notes.trim() || null,
      });

      Alert.alert('Prenotato!', `Ci vediamo ${dateRelative(selectedDate).toLowerCase()} alle ${selectedSlot.time}`, [
        { text: 'OK', onPress: () => router.replace(`/booking/${created.id}`) },
      ]);
    } catch (err: any) {
      const code = err?.response?.data?.error?.code;
      const msg = err?.response?.data?.error?.message ?? 'Prenotazione fallita.';
      if (code === 'slot_unavailable') {
        Alert.alert('Slot occupato', 'Qualcuno è stato più veloce. Scegline un altro.');
        void loadSlots();
        return;
      }
      Alert.alert('Ops', msg);
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <SafeAreaView className="flex-1 bg-cream-light dark:bg-dark-bg" edges={['top']}>
      <View className="flex-row items-center justify-between px-5 pt-3 pb-2">
        <Pressable onPress={() => router.back()} className="w-10 h-10 items-center justify-center -ml-1">
          <ChevronLeft size={26} color="#1F2419" strokeWidth={1.5} />
        </Pressable>
        <Text className="font-display-italic text-[18px] text-ink dark:text-cream">Prenota</Text>
        <View className="w-10" />
      </View>

      <ScrollView contentContainerStyle={{ paddingBottom: 160 }} keyboardShouldPersistTaps="handled">
        {/* Date strip */}
        <Section eyebrow="Quando?" title="Scegli il giorno">
          <ScrollView horizontal showsHorizontalScrollIndicator={false} contentContainerStyle={{ paddingHorizontal: 4, gap: 8 }}>
            {dates.map((d) => (
              <DateCell key={d.iso} d={d} selected={d.iso === selectedDate} onPress={() => setSelectedDate(d.iso)} />
            ))}
          </ScrollView>
        </Section>

        {/* Duration pills */}
        <Section eyebrow="Durata">
          <View className="flex-row gap-2 px-1">
            {([60, 90, 120] as Duration[]).map((d) => (
              <Pressable
                key={d}
                onPress={() => setDuration(d)}
                className={`px-5 py-2.5 rounded-full ${duration === d ? 'bg-sage' : 'bg-white dark:bg-dark-surface'}`}
                style={
                  duration === d
                    ? { shadowColor: '#6B8068', shadowOpacity: 0.28, shadowRadius: 12, shadowOffset: { width: 0, height: 4 } }
                    : { shadowColor: '#6B8068', shadowOpacity: 0.06, shadowRadius: 6, shadowOffset: { width: 0, height: 2 } }
                }
              >
                <Text className={`font-body-semi text-[13px] ${duration === d ? 'text-cream' : 'text-ink dark:text-cream'}`}>
                  {DURATION_LABELS[d]}
                </Text>
              </Pressable>
            ))}
          </View>
        </Section>

        {/* Slot grid */}
        <Section eyebrow={`Orari disponibili · ${dateRelative(selectedDate)}`}>
          {loadingSlots ? (
            <View className="py-8 items-center"><ActivityIndicator color="#6B8068" /></View>
          ) : slots.length === 0 ? (
            <Text className="font-body-medium text-[13px] text-ink-muted text-center py-6">
              Calendario non disponibile. Riprova.
            </Text>
          ) : (
            <View className="flex-row flex-wrap gap-2 px-1">
              {slots.map((s, i) => (
                <SlotCell
                  key={`${s.time}-${i}`}
                  slot={s}
                  selected={selectedSlot?.time === s.time}
                  onPress={() => s.available && setSelectedSlot(s)}
                />
              ))}
            </View>
          )}
        </Section>

        {/* Type */}
        <Section eyebrow="Tipo di partita">
          <View className="bg-white dark:bg-dark-surface rounded-3xl overflow-hidden">
            <TypeOption label="Con avversario" sub="Conosco con chi gioco" selected={type === 'con_avversario'} onPress={() => setType('con_avversario')} />
            <Divider />
            <TypeOption label="Trova avversario" sub="Matchmaking ELO" selected={type === 'matchmaking'} onPress={() => setType('matchmaking')} />
            <Divider />
            <TypeOption label="Sparapalline" sub="Allenamento da solo" selected={type === 'sparapalline'} onPress={() => setType('sparapalline')} />
          </View>
        </Section>

        {/* Opponent picker (only if con_avversario) */}
        {type === 'con_avversario' && (
          <Section eyebrow="Con chi giochi?">
            {opponent ? (
              <View className="bg-white dark:bg-dark-surface rounded-2xl px-4 py-3 flex-row items-center gap-3">
                <Avatar url={opponent.avatar_url} name={opponent.name} size={40} />
                <View className="flex-1">
                  <Text className="font-display-semi text-[15px] text-ink dark:text-cream">{opponent.name}</Text>
                  <Text className="font-body-medium text-[11px] text-ink-muted">
                    ELO {opponent.elo_rating ?? '—'}{opponent.fit_rating ? ` · FIT ${opponent.fit_rating}` : ''}
                  </Text>
                </View>
                <Pressable onPress={() => { setOpponent(null); setOpponentQuery(''); }} className="w-8 h-8 items-center justify-center">
                  <X size={18} color="#7A7E72" strokeWidth={2} />
                </Pressable>
              </View>
            ) : (
              <>
                <View className="bg-white dark:bg-dark-surface rounded-2xl px-4 flex-row items-center">
                  <Search size={18} color="#7A7E72" strokeWidth={1.5} />
                  <TextInput
                    value={opponentQuery}
                    onChangeText={setOpponentQuery}
                    placeholder="Cerca un socio del circolo"
                    placeholderTextColor="#7A7E72"
                    className="flex-1 ml-2 py-3 font-body-medium text-[15px] text-ink dark:text-cream"
                  />
                </View>

                {opponentResults.length > 0 && (
                  <View className="bg-white dark:bg-dark-surface rounded-2xl mt-2 overflow-hidden">
                    {opponentResults.map((p, i) => (
                      <View key={p.id}>
                        {i > 0 && <Divider />}
                        <Pressable
                          onPress={() => { setOpponent(p); Keyboard.dismiss(); }}
                          className="flex-row items-center gap-3 px-4 py-3"
                        >
                          <Avatar url={p.avatar_url} name={p.name} size={36} />
                          <View className="flex-1">
                            <Text className="font-display-semi text-[14px] text-ink dark:text-cream">{p.name}</Text>
                            <Text className="font-body-medium text-[11px] text-ink-muted">
                              ELO {p.elo_rating ?? '—'}{p.fit_rating ? ` · FIT ${p.fit_rating}` : ''}
                            </Text>
                          </View>
                        </Pressable>
                      </View>
                    ))}
                  </View>
                )}

                {opponentQuery.trim().length >= 2 && opponentResults.length === 0 && (
                  <View className="bg-cream rounded-2xl mt-2 p-3">
                    <Text className="font-body-medium text-[12px] text-ink-muted leading-relaxed">
                      Nessun socio trovato. Aggiungilo come nome libero:
                    </Text>
                    <TextInput
                      value={opponentFreeText}
                      onChangeText={setOpponentFreeText}
                      placeholder="Nome avversario (esterno)"
                      placeholderTextColor="#7A7E72"
                      className="bg-white rounded-xl px-3 py-2 mt-2 font-body-medium text-[14px] text-ink"
                    />
                    <Text className="font-body-medium text-[10px] text-ink-muted mt-1.5">
                      Senza ELO — la partita non conta in classifica.
                    </Text>
                  </View>
                )}
              </>
            )}
          </Section>
        )}

        {/* Notes */}
        <Section eyebrow="Note (opzionale)">
          <View className="bg-white dark:bg-dark-surface rounded-2xl px-4 py-3">
            <TextInput
              value={notes}
              onChangeText={setNotes}
              placeholder="Es. 'Porto io le palle'"
              placeholderTextColor="#7A7E72"
              multiline
              maxLength={500}
              className="font-body-medium text-[14px] text-ink dark:text-cream"
              style={{ minHeight: 50 }}
            />
          </View>
        </Section>
      </ScrollView>

      {/* Bottom bar */}
      <View className="absolute left-0 right-0 bottom-0 px-5 pt-3 pb-7 bg-cream-light dark:bg-dark-bg border-t border-divider" style={{ borderColor: 'rgba(31,36,25,0.06)' }}>
        <View className="flex-row items-center mb-3">
          <View className="flex-1">
            <Text className="font-body-bold text-[10px] tracking-widest uppercase text-ink-muted">
              {selectedSlot ? 'Hai scelto' : 'Scegli uno slot'}
            </Text>
            {selectedSlot && (
              <Text className="font-display-italic text-[16px] text-ink dark:text-cream mt-0.5">
                {dateRelative(selectedDate)} · {selectedSlot.time} — {selectedSlot.end_time}
              </Text>
            )}
          </View>
          {selectedSlot && (
            <Text className="font-display-semi text-[22px] text-ink dark:text-cream">{money(selectedSlot.price)}</Text>
          )}
        </View>
        <Pressable
          disabled={!canSubmit}
          onPress={submit}
          className={`w-full py-[16px] rounded-full items-center ${canSubmit ? 'bg-sage' : 'bg-sage/40'}`}
          style={{
            shadowColor: '#6B8068',
            shadowOpacity: canSubmit ? 0.32 : 0,
            shadowRadius: 20,
            shadowOffset: { width: 0, height: 6 },
          }}
        >
          {submitting ? (
            <ActivityIndicator color="#ECE3CE" />
          ) : (
            <Text className="font-body-bold text-[15px] text-cream tracking-wide">
              {selectedSlot ? `Prenota · ${money(selectedSlot.price)}` : 'Conferma'}
            </Text>
          )}
        </Pressable>
      </View>
    </SafeAreaView>
  );
}

// ── Sub-components ───────────────────────────────────────────────────

function Section({ eyebrow, title, children }: { eyebrow: string; title?: string; children: React.ReactNode }) {
  return (
    <View className="px-6 mt-5">
      <Text className="font-body-bold text-[10px] tracking-widest uppercase text-ink-muted mb-2">{eyebrow}</Text>
      {title && <Text className="font-display-italic text-[20px] text-ink dark:text-cream mb-3">{title}</Text>}
      {children}
    </View>
  );
}

function DateCell({ d, selected, onPress }: { d: ReturnType<typeof buildDateStrip>[number]; selected: boolean; onPress: () => void }) {
  const SHARED = { width: 56, paddingVertical: 12, borderRadius: 20, alignItems: 'center' as const };

  if (selected) {
    return (
      <Pressable onPress={onPress}>
        <LinearGradient colors={['#6B8068', '#8AA086']} start={{ x: 0, y: 0 }} end={{ x: 1, y: 1 }}
          style={{ ...SHARED, shadowColor: '#6B8068', shadowOpacity: 0.32, shadowRadius: 14, shadowOffset: { width: 0, height: 4 } }}>
          <Text className="font-body-bold text-[10px] tracking-widest uppercase text-cream">{d.dayShort}</Text>
          <Text className="font-display-semi text-[22px] text-cream mt-0.5">{d.dayNum}</Text>
        </LinearGradient>
      </Pressable>
    );
  }

  return (
    <Pressable
      onPress={onPress}
      style={{ ...SHARED, backgroundColor: '#FFFFFF', shadowColor: '#6B8068', shadowOpacity: 0.06, shadowRadius: 6, shadowOffset: { width: 0, height: 2 } }}
    >
      <Text className="font-body-bold text-[10px] tracking-widest uppercase text-ink-muted">{d.dayShort}</Text>
      <Text className="font-display-semi text-[22px] text-ink mt-0.5">{d.dayNum}</Text>
    </Pressable>
  );
}

function SlotCell({ slot, selected, onPress }: { slot: AvailabilitySlot; selected: boolean; onPress: () => void }) {
  const base = 'rounded-2xl px-3 py-2.5';
  const width = '31%';

  if (selected) {
    return (
      <Pressable onPress={onPress} style={{ width: '31%' }}>
        <LinearGradient colors={['#6B8068', '#8AA086']} start={{ x: 0, y: 0 }} end={{ x: 1, y: 1 }}
          style={{ borderRadius: 20, paddingHorizontal: 12, paddingVertical: 12, shadowColor: '#6B8068', shadowOpacity: 0.32, shadowRadius: 14, shadowOffset: { width: 0, height: 4 } }}>
          <View className="flex-row items-center justify-between">
            <Text className="font-display-semi text-[16px] text-cream">{slot.time}</Text>
            <Check size={14} color="#ECE3CE" strokeWidth={3} />
          </View>
          <Text className="font-body-semi text-[11px] text-cream/85 mt-0.5">{money(slot.price)}</Text>
        </LinearGradient>
      </Pressable>
    );
  }

  if (!slot.available) {
    return (
      <View style={{ width }} className={`${base} bg-white dark:bg-dark-surface opacity-40`}>
        <Text className="font-display-semi text-[16px] text-ink dark:text-cream">{slot.time}</Text>
        <Text className="font-body-medium text-[10px] text-ink-muted mt-0.5">Occupato</Text>
      </View>
    );
  }

  return (
    <Pressable onPress={onPress} style={{ width }} className={`${base} bg-white dark:bg-dark-surface`}>
      <Text className="font-display-semi text-[16px] text-ink dark:text-cream">{slot.time}</Text>
      <Text className="font-body-medium text-[10px] text-ink-muted mt-0.5">{money(slot.price)}</Text>
    </Pressable>
  );
}

function TypeOption({ label, sub, selected, onPress }: { label: string; sub: string; selected: boolean; onPress: () => void }) {
  return (
    <Pressable onPress={onPress} className="flex-row items-center px-5 py-4 gap-3">
      <View className={`w-5 h-5 rounded-full border-2 items-center justify-center ${selected ? 'border-sage' : 'border-divider'}`}>
        {selected && <View className="w-2.5 h-2.5 rounded-full bg-sage" />}
      </View>
      <View className="flex-1">
        <Text className="font-body-semi text-[14px] text-ink dark:text-cream">{label}</Text>
        <Text className="font-body-medium text-[12px] text-ink-muted">{sub}</Text>
      </View>
    </Pressable>
  );
}

function Divider() {
  return <View style={{ height: 1, backgroundColor: 'rgba(31,36,25,0.06)', marginHorizontal: 20 }} />;
}

// ── Helpers ──────────────────────────────────────────────────────────

const ITA_DAYS_SHORT = ['Dom', 'Lun', 'Mar', 'Mer', 'Gio', 'Ven', 'Sab'];

function buildDateStrip(count: number) {
  const result: { iso: string; dayShort: string; dayNum: number; date: Date }[] = [];
  const today = new Date();
  for (let i = 0; i < count; i++) {
    const d = new Date(today.getFullYear(), today.getMonth(), today.getDate() + i);
    const iso = `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
    result.push({ iso, dayShort: ITA_DAYS_SHORT[d.getDay()], dayNum: d.getDate(), date: d });
  }
  return result;
}
