import { Stack } from 'expo-router';

export default function FeedbackLayout() {
  return (
    <Stack screenOptions={{ headerShown: false, animation: 'slide_from_right' }}>
      <Stack.Screen name="[bookingId]" />
    </Stack>
  );
}
