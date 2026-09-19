import { Field, type FieldShellProps } from '@/components/form/Field';
import { Switch } from '@/components/ui/switch';

/** Label on the start edge, control on the end edge — mirrored automatically in RTL. */
export function SwitchField({
    checked,
    onChange,
    disabled,
    ...shell
}: FieldShellProps & { checked: boolean; onChange: (checked: boolean) => void; disabled?: boolean }) {
    return (
        <Field
            {...shell}
            inline
            /*
             * `required` is dropped here, and only here (D-19).
             *
             * On an input it means "this must be filled in". On a switch it would mean "this must
             * be ON", which is not what any caller means by marking a toggle required — and a
             * browser would refuse the whole save until the operator turned a switch they had
             * deliberately turned off. `aria-required` stays, because announcing "required" when
             * the control is reached is still true and still useful.
             */
            render={({ required: _required, ...attrs }) => (
                <Switch {...attrs} checked={checked} disabled={disabled} onCheckedChange={onChange} />
            )}
        />
    );
}
